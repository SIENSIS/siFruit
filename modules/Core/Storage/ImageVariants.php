<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\I18n\Time;
use Config\Storage as StorageConfig;
use GdImage;
use Throwable;

/**
 * The responsive copies of public images (D-54-01, docs/storage.md): smaller copies at the widths
 * of `Config\Storage::$imageVariantWidths` (only those narrower than the image), in WebP (or the
 * image's own format when GD has no WebP, or when a PNG/GIF copy is smaller than the WebP one), stored beside the file with derived names
 * (`modules/news/2026/11/<hex>-w400.webp`) and recorded in `stored_file_variants`.
 *
 * Generated **on upload** (the Storage service) and by the image optimiser only, never on demand
 * from a URL. Private files get none (the Files module keeps its own thumbnails).
 */
final class ImageVariants
{
    /** @var array<int, list<array<string, mixed>>> variants per stored file id (this request) */
    private array $cache = [];

    public function __construct(private readonly Storage $storage, private readonly ?StorageConfig $config = null)
    {
    }

    private function config(): StorageConfig
    {
        return $this->config ?? $this->storage->config();
    }

    /**
     * Whether a stored file should have variants: a raster image on a public disk.
     */
    public function eligible(StoredFile $file): bool
    {
        return $file->isImage() && $file->width !== null && $file->height !== null
            && isset($this->config()->disks[$file->disk]) && $this->storage->disk($file->disk)->isPublic();
    }

    /**
     * The widths a file should have variants at (configured widths narrower than the image).
     *
     * @return list<int>
     */
    public function widthsFor(StoredFile $file): array
    {
        if (! $this->eligible($file)) {
            return [];
        }

        $widths = array_values(array_unique(array_filter(
            array_map('intval', $this->config()->imageVariantWidths),
            static fn (int $width): bool => $width > 0 && $width < (int) $file->width,
        )));
        sort($widths);

        return $widths;
    }

    /**
     * The widths a file still lacks.
     *
     * @return list<int>
     */
    public function missing(StoredFile $file): array
    {
        $have = array_map(static fn (array $row): int => (int) $row['width'], $this->of($file->id));

        return array_values(array_diff($this->widthsFor($file), $have));
    }

    /**
     * The stored variants of a file, narrowest first.
     *
     * @return list<array{id: int, stored_file_id: int, width: int, height: int, format: string, path: string, size: int}>
     */
    public function of(int $fileId): array
    {
        if (! isset($this->cache[$fileId])) {
            $this->prefetch([$fileId]);
        }

        /** @var list<array{id: int, stored_file_id: int, width: int, height: int, format: string, path: string, size: int}> */
        return $this->cache[$fileId] ?? [];
    }

    /**
     * Loads the variants of several files with one query (views listing many images).
     *
     * @param list<int> $fileIds
     */
    public function prefetch(array $fileIds): void
    {
        $fileIds = array_values(array_diff(array_unique(array_map('intval', $fileIds)), array_keys($this->cache)));

        if ($fileIds === []) {
            return;
        }

        foreach ($fileIds as $id) {
            $this->cache[$id] = [];
        }

        try {
            $rows = $this->db()->table('stored_file_variants')->whereIn('stored_file_id', $fileIds)->orderBy('width')->get()->getResultArray();
        } catch (Throwable) {
            return; // table not migrated yet: no variants
        }

        foreach ($rows as $row) {
            $this->cache[(int) $row['stored_file_id']][] = [
                'id'             => (int) $row['id'],
                'stored_file_id' => (int) $row['stored_file_id'],
                'width'          => (int) $row['width'],
                'height'         => (int) $row['height'],
                'format'         => (string) $row['format'],
                'path'           => (string) $row['path'],
                'size'           => (int) $row['size'],
            ];
        }
    }

    /**
     * Generates the missing variants of a file (all of them with `$replace`).
     *
     * @return int bytes written
     *
     * @throws StorageException when the image cannot be decoded or a variant cannot be written
     */
    public function generate(StoredFile $file, bool $replace = false): int
    {
        if ($replace) {
            $this->delete($file);
        }

        $widths = $this->missing($file);

        if ($widths === []) {
            return 0;
        }

        $disk = $this->storage->disk($file->disk);

        if (! ImageReencoder::fitsInMemory((int) $file->width, (int) $file->height)) {
            throw new StorageException(lang('Core.storage.refused.memory', [$file->width . '×' . $file->height]));
        }

        $image = @imagecreatefromstring($disk->read($file->path));

        if (! $image instanceof GdImage) {
            throw new StorageException(lang('Core.storage.refused.image'));
        }

        $format = self::format($file->extension);
        $base   = substr($file->path, 0, (int) strrpos($file->path, '.'));
        $bytes  = 0;

        foreach ($widths as $width) {
            $height   = max(1, (int) round(imagesy($image) * $width / imagesx($image)));
            $copy     = ImageReencoder::resample($image, $width, $height);
            $chosen   = $format;
            $contents = $this->encode($copy, $format);

            // Flat graphics (logos, diagrams) are often smaller as PNG/GIF than as lossy WebP:
            // keep whichever is smaller, so a variant never weighs more than it needs to.
            if ($format === 'webp' && in_array($file->extension, ['png', 'gif'], true)) {
                $own = $this->encode(ImageReencoder::resample($image, $width, $height), $file->extension);

                if (strlen($own) < strlen($contents)) {
                    [$chosen, $contents] = [$file->extension, $own];
                }
            }

            $path = $base . '-w' . $width . '.' . $chosen;

            $disk->write($path, $contents);

            try {
                $this->db()->table('stored_file_variants')->insert([
                    'stored_file_id' => $file->id,
                    'width'          => $width,
                    'height'         => $height,
                    'format'         => $chosen,
                    'path'           => $path,
                    'size'           => strlen($contents),
                    'created_at'     => Time::now()->toDateTimeString(),
                ]);
            } catch (Throwable $e) {
                $disk->delete($path);

                throw new StorageException($e->getMessage());
            }

            $bytes += strlen($contents);
        }

        unset($this->cache[$file->id]);

        return $bytes;
    }

    /**
     * Deletes the variants of a file (files and rows).
     */
    public function delete(StoredFile $file): void
    {
        try {
            $rows = $this->db()->table('stored_file_variants')->where('stored_file_id', $file->id)->get()->getResultArray();
        } catch (Throwable) {
            return;
        }

        $disk = $this->storage->disk($file->disk);

        foreach ($rows as $row) {
            try {
                if ($disk->exists((string) $row['path'])) {
                    $disk->delete((string) $row['path']);
                }
            } catch (Throwable) {
                // an invalid stored path: the row goes anyway
            }
        }

        $this->db()->table('stored_file_variants')->where('stored_file_id', $file->id)->delete();
        unset($this->cache[$file->id]);
    }

    /**
     * The URL of a variant (public disks only).
     */
    public function url(StoredFile $file, string $path): string
    {
        return $this->storage->disk($file->disk)->url($path);
    }

    /**
     * WebP when GD can write it, else the image's own format.
     */
    public static function format(string $extension): string
    {
        if (function_exists('imagewebp') && (imagetypes() & IMG_WEBP) !== 0) {
            return 'webp';
        }

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    private function encode(GdImage $image, string $format): string
    {
        $quality = max(1, min(100, $this->config()->variantQuality));

        ob_start();

        try {
            $ok = match ($format) {
                'webp'  => self::alpha($image) && imagewebp($image, null, $quality),
                'png'   => self::alpha($image) && imagepng($image, null, 6),
                'gif'   => imagegif($image),
                default => imagejpeg($image, null, $quality),
            };
        } finally {
            $contents = (string) ob_get_clean();
        }

        if (! $ok || $contents === '') {
            throw new StorageException(lang('Core.storage.refused.image'));
        }

        return $contents;
    }

    private static function alpha(GdImage $image): bool
    {
        imagealphablending($image, false);

        return imagesavealpha($image, true);
    }

    /**
     * @return BaseConnection<object|resource, object|resource>
     */
    private function db(): BaseConnection
    {
        return db_connect();
    }
}
