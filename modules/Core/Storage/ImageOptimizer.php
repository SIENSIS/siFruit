<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Optimises images already stored (D-54-01, docs/storage.md): scales down in place every image
 * above `Core.imageMaxDimension` (re-encoded, metadata stripped, same key, path and URL;
 * `stored_files` updated) and generates the missing variants of public images. Used by
 * `php spark storage:optimize`, the weekly `OptimizeImagesTask` and System information; none of
 * them repeats this logic.
 *
 * - Files stored with `reencode: false` (`stored_files.reencoded` = 0) are never touched.
 * - A file that fails is marked (`optimize_failed_at`, `optimize_error`) and skipped by the next
 *   runs (no retry loop); `retryFailed()` clears the marks.
 * - Never moves files into dated folders (URLs stored in rich text would break) and never
 *   deletes anything but the replaced bytes and stale variants of a resized image.
 */
final class ImageOptimizer
{
    /** MIME types the optimiser processes (raster images GD re-encodes). */
    public const MIMES = ['image/jpeg', 'image/pjpeg', 'image/png', 'image/x-png', 'image/gif', 'image/webp'];

    /** Rows read per query while looking for work. */
    private const CHUNK = 500;

    private ?int $maxDimension;

    public function __construct(private readonly ?Storage $storage = null, ?int $maxDimension = null)
    {
        $this->maxDimension = $maxDimension;
    }

    public function maxDimension(): int
    {
        return $this->maxDimension ??= UploadValidator::globalMaxDimension();
    }

    private function storage(): Storage
    {
        /** @var Storage */
        return $this->storage ?? service('storage');
    }

    /**
     * Images waiting: above the limit, public ones without any variant (although they are wider
     * than the narrowest width), and files a run could not process.
     *
     * @return array{oversized: int, withoutVariants: int, failed: int}
     */
    public function pending(): array
    {
        $max    = $this->maxDimension();
        $widths = array_filter(array_map('intval', $this->storage()->config()->imageVariantWidths), static fn (int $w): bool => $w > 0);
        $public = $this->publicDisks();

        $oversized = $this->images()->where('optimize_failed_at', null)->where('reencoded', 1)
            ->groupStart()->where('width >', $max)->orWhere('height >', $max)->groupEnd()
            ->countAllResults();

        $without = 0;

        if ($widths !== [] && $public !== []) {
            $without = $this->images()->where('optimize_failed_at', null)->whereIn('disk', $public)
                ->where('width >', min($widths))
                ->where('NOT EXISTS (SELECT 1 FROM ' . $this->db()->prefixTable('stored_file_variants') . ' v WHERE v.stored_file_id = ' . $this->db()->prefixTable('stored_files') . '.id)', null, false)
                ->countAllResults();
        }

        $failed = $this->db()->table('stored_files')->where('optimize_failed_at IS NOT NULL', null, false)->countAllResults();

        return ['oversized' => $oversized, 'withoutVariants' => $without, 'failed' => $failed];
    }

    /**
     * The files a run could not process (newest first).
     *
     * @return list<array{id: int, disk: string, path: string, original_name: string, failed_at: string, error: string}>
     */
    public function failures(int $limit = 50): array
    {
        $rows = $this->db()->table('stored_files')->select('id, disk, path, original_name, optimize_failed_at, optimize_error')
            ->where('optimize_failed_at IS NOT NULL', null, false)->orderBy('optimize_failed_at', 'desc')->limit($limit)->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id'            => (int) $row['id'],
            'disk'          => (string) $row['disk'],
            'path'          => (string) $row['path'],
            'original_name' => (string) $row['original_name'],
            'failed_at'     => (string) $row['optimize_failed_at'],
            'error'         => (string) $row['optimize_error'],
        ], $rows);
    }

    /**
     * Clears the failure marks so the next run tries those files again.
     */
    public function retryFailed(): int
    {
        $this->db()->table('stored_files')->where('optimize_failed_at IS NOT NULL', null, false)->update(['optimize_failed_at' => null, 'optimize_error' => null]);

        return $this->db()->affectedRows();
    }

    /**
     * Processes at most `$limit` files (and at most `$seconds` seconds; 0 = no time budget).
     *
     * @param array{disk?: string|null, folder?: string|null, limit?: int, seconds?: int, dryRun?: bool} $options
     *
     * @return array{processed: int, resized: int, variants: int, bytesBefore: int, bytesAfter: int, failed: list<array{id: int, path: string, error: string}>, left: int, dryRun: bool, files: list<array{id: int, path: string, before: int, after: int, resized: bool, variants: int}>}
     */
    public function run(array $options = []): array
    {
        $dryRun  = (bool) ($options['dryRun'] ?? false);
        $limit   = max(1, (int) ($options['limit'] ?? PHP_INT_MAX));
        $seconds = max(0, (int) ($options['seconds'] ?? 0));
        $start   = microtime(true);
        $summary = ['processed' => 0, 'resized' => 0, 'variants' => 0, 'bytesBefore' => 0, 'bytesAfter' => 0, 'failed' => [], 'left' => 0, 'dryRun' => $dryRun, 'files' => []];
        $stopped = false;

        foreach ($this->candidates($options['disk'] ?? null, $options['folder'] ?? null) as $file) {
            if ($summary['processed'] + count($summary['failed']) >= $limit || ($seconds > 0 && microtime(true) - $start >= $seconds)) {
                $stopped = true;
                $summary['left']++;

                continue;
            }

            try {
                $result = $this->process($file, $dryRun);
            } catch (Throwable $e) {
                $summary['failed'][] = ['id' => $file->id, 'path' => $file->path, 'error' => $e->getMessage()];

                if (! $dryRun) {
                    $this->storage()->markFailed($file, $e->getMessage());
                }

                continue;
            }

            $summary['processed']++;
            $summary['resized'] += $result['resized'] ? 1 : 0;
            $summary['variants'] += $result['variants'];
            $summary['bytesBefore'] += $result['before'];
            $summary['bytesAfter'] += $result['after'];
            $summary['files'][] = ['id' => $file->id, 'path' => $file->path] + $result;
        }

        if (! $stopped) {
            $summary['left'] = 0;
        }

        return $summary;
    }

    /**
     * Whether a file has work to do: above the limit, or a public image missing variants.
     */
    public function needsWork(StoredFile $file): bool
    {
        [$width, $height] = [(int) $file->width, (int) $file->height];

        if (max($width, $height) > $this->maxDimension()) {
            return true;
        }

        return $this->storage()->variants()->missing($file) !== [];
    }

    /**
     * Resizes one file in place (when above the limit) and generates its missing variants.
     * `before`/`after`: bytes of the file and its variants.
     *
     * @return array{before: int, after: int, resized: bool, variants: int}
     *
     * @throws Throwable when the image cannot be processed
     */
    public function process(StoredFile $file, bool $dryRun = false): array
    {
        $storage  = $this->storage();
        $variants = $storage->variants();
        $disk     = $storage->disk($file->disk);
        $existing = array_sum(array_map(static fn (array $row): int => $row['size'], $variants->of($file->id)));
        $before   = $file->size + $existing;
        $resized  = false;
        $current  = $file;

        if (max((int) $file->width, (int) $file->height) > $this->maxDimension()) {
            if (! ImageReencoder::fitsInMemory((int) $file->width, (int) $file->height)) {
                throw new StorageException(lang('Core.storage.refused.memory', [$file->width . '×' . $file->height]));
            }

            $temp = tempnam(sys_get_temp_dir(), 'kpo');

            if ($temp === false) {
                throw new StorageException('No temporary file.');
            }

            try {
                file_put_contents($temp, $disk->read($file->path));
                $image = (new ImageReencoder($storage->config()->imageQuality, $this->maxDimension()))->reencode($temp, $file->extension);
            } finally {
                unlink($temp);
            }

            $resized = true;
            $current = new StoredFile(
                $file->id,
                $file->key,
                $file->disk,
                $file->path,
                $file->originalName,
                $file->extension,
                $file->mime,
                strlen($image['contents']),
                hash('sha256', $image['contents']),
                $image['width'],
                $image['height'],
                $file->uploadedBy,
                $file->createdAt,
            );

            if (! $dryRun) {
                $disk->write($file->path, $image['contents']);
                $this->db()->table('stored_files')->where('id', $file->id)->update([
                    'size'     => $current->size,
                    'checksum' => $current->checksum,
                    'width'    => $current->width,
                    'height'   => $current->height,
                ]);
            }
        }

        if ($dryRun) {
            $missing = $resized ? $variants->widthsFor($current) : $variants->missing($current);

            // Estimate: a WebP variant weighs about its share of the pixels of the image.
            $estimate = 0;

            foreach ($missing as $width) {
                $estimate += (int) round($current->size * ($width / max(1, (int) $current->width)) ** 2 * 0.6);
            }

            return ['before' => $before, 'after' => $current->size + ($resized ? 0 : $existing) + $estimate, 'resized' => $resized, 'variants' => count($missing)];
        }

        $count   = count($resized ? $variants->widthsFor($current) : $variants->missing($current));
        $written = $variants->generate($current, $resized);

        if ($count === 0 && ! $resized) {
            $written = 0;
        }

        $after = $current->size + ($resized ? 0 : $existing) + $written;

        return ['before' => $before, 'after' => $after, 'resized' => $resized, 'variants' => $count];
    }

    /**
     * The stored images with work to do, oldest first (read in chunks).
     *
     * @return iterable<StoredFile>
     */
    public function candidates(?string $disk = null, ?string $folder = null): iterable
    {
        $lastId = 0;
        $folder = $folder === null || $folder === '' ? null : PathNormalizer::normalize($folder);

        while (true) {
            $builder = $this->images()->where('id >', $lastId)->where('reencoded', 1)->where('optimize_failed_at', null);

            if ($disk !== null && $disk !== '') {
                $builder->where('disk', $disk);
            }

            if ($folder !== null) {
                $builder->like('path', $folder . '/', 'after');
            }

            $rows = $builder->orderBy('id')->limit(self::CHUNK)->get()->getResultArray();

            if ($rows === []) {
                return;
            }

            $files = array_map(static fn (array $row): StoredFile => StoredFile::fromRow($row), $rows);
            $this->storage()->variants()->prefetch(array_map(static fn (StoredFile $file): int => $file->id, $files));

            foreach ($files as $file) {
                $lastId = $file->id;

                if ($file->width !== null && $file->height !== null && $this->needsWork($file)) {
                    yield $file;
                }
            }
        }
    }

    /**
     * Bytes stored per disk, per module folder (`modules/<module>`) and of the variants.
     *
     * @return array{disks: array<string, array{bytes: int, files: int}>, modules: array<string, array{bytes: int, files: int}>, variants: array{bytes: int, files: int}}
     */
    public function usage(): array
    {
        $disks = [];

        foreach ($this->db()->table('stored_files')->select('disk, COUNT(*) AS files, SUM(size) AS bytes')->groupBy('disk')->get()->getResultArray() as $row) {
            $disks[(string) $row['disk']] = ['bytes' => (int) $row['bytes'], 'files' => (int) $row['files']];
        }

        $modules = [];
        $lastId  = 0;

        do {
            $rows = $this->db()->table('stored_files')->select('id, disk, path, size')->where('id >', $lastId)
                ->like('path', 'modules/', 'after')->orderBy('id')->limit(5000)->get()->getResultArray();

            foreach ($rows as $row) {
                $lastId = (int) $row['id'];

                if (preg_match('#^(modules/[^/]+)/#', (string) $row['path'], $match) === 1) {
                    $key = $row['disk'] . ':' . $match[1];
                    $modules[$key] ??= ['bytes' => 0, 'files' => 0];
                    $modules[$key]['bytes'] += (int) $row['size'];
                    $modules[$key]['files']++;
                }
            }
        } while (count($rows) === 5000);

        ksort($modules);

        $variants = $this->db()->table('stored_file_variants')->select('COUNT(*) AS files, SUM(size) AS bytes')->get()->getRowArray();

        return [
            'disks'    => $disks,
            'modules'  => $modules,
            'variants' => ['bytes' => (int) ($variants['bytes'] ?? 0), 'files' => (int) ($variants['files'] ?? 0)],
        ];
    }

    /**
     * @return list<string>
     */
    private function publicDisks(): array
    {
        $disks = [];

        foreach ($this->storage()->config()->disks as $name => $disk) {
            if ($disk['url'] !== null) {
                $disks[] = $name;
            }
        }

        return $disks;
    }

    /**
     * @return BaseBuilder<BaseConnection<object|resource, object|resource>>
     */
    private function images(): BaseBuilder
    {
        return $this->db()->table('stored_files')->whereIn('mime', self::MIMES)->whereIn('disk', array_keys($this->storage()->config()->disks));
    }

    /**
     * @return BaseConnection<object|resource, object|resource>
     */
    private function db(): BaseConnection
    {
        return db_connect();
    }
}
