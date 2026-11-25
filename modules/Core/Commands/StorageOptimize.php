<?php

declare(strict_types=1);

namespace Modules\Core\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Storage\ImageOptimizer;
use Modules\Core\Storage\PathNormalizer;
use Modules\Core\Storage\Storage;
use Modules\Core\Storage\StorageException;
use Modules\Core\System\SystemReport;

/**
 * php spark storage:optimize [--dry-run] [--disk=public|private] [--folder=<path>] [--limit=N] [--retry-failed]
 *
 * Shrinks stored images above `Core.imageMaxDimension` in place (same key and URL) and generates
 * the missing variants of public images (D-54-01, docs/storage.md). Resumable and idempotent: a
 * second run finds nothing left. `--dry-run` writes nothing. One activity-log entry per real run.
 * The weekly `OptimizeImagesTask` does the same in bounded batches.
 */
final class StorageOptimize extends BaseCommand
{
    protected $group       = 'Application';
    protected $name        = 'storage:optimize';
    protected $description = 'Scales down stored images above the size limit and creates the missing variants of public images.';
    protected $usage       = 'storage:optimize [--dry-run] [--disk=public|private] [--folder=<path>] [--limit=N] [--retry-failed]';

    /** @var array<string, string> */
    protected $options = [
        '--dry-run'      => 'Show what would be done; write nothing.',
        '--disk'         => 'Only this disk (public or private).',
        '--folder'       => 'Only files under this folder of the disk (e.g. modules/news).',
        '--limit'        => 'Process at most N files (the next run continues).',
        '--retry-failed' => 'Try again the files earlier runs could not process.',
    ];

    /**
     * @param array<int|string, string|null> $params
     *
     * @return int
     */
    public function run(array $params)
    {
        /** @var Storage $storage */
        $storage = service('storage');
        $dryRun  = CLI::getOption('dry-run') !== null || array_key_exists('dry-run', $params);
        $disk    = self::option('disk');
        $folder  = self::option('folder');
        $limit   = self::option('limit');

        if ($disk !== null && ! isset($storage->config()->disks[$disk])) {
            CLI::error(lang('Core.optimize.badDisk', [$disk]));

            return EXIT_ERROR;
        }

        if ($folder !== null) {
            try {
                $folder = PathNormalizer::normalize($folder);
            } catch (StorageException) {
                CLI::error(lang('Core.optimize.badFolder', [$folder]));

                return EXIT_ERROR;
            }
        }

        if ($limit !== null && (! ctype_digit($limit) || (int) $limit < 1)) {
            CLI::error(lang('Core.optimize.badLimit'));

            return EXIT_ERROR;
        }

        $optimizer = new ImageOptimizer($storage);

        if (! $dryRun && (CLI::getOption('retry-failed') !== null || array_key_exists('retry-failed', $params))) {
            CLI::write(lang('Core.optimize.retried', [$optimizer->retryFailed()]));
        }

        CLI::write(lang('Core.optimize.limit', [$optimizer->maxDimension(), implode(', ', $storage->config()->imageVariantWidths)]));

        if ($dryRun) {
            CLI::write(lang('Core.optimize.dryRun'), 'yellow');
        }

        $summary = $optimizer->run(['disk' => $disk, 'folder' => $folder, 'limit' => $limit === null ? PHP_INT_MAX : (int) $limit, 'dryRun' => $dryRun]);

        foreach ($summary['files'] as $file) {
            CLI::write(lang('Core.optimize.file', [
                $file['path'],
                SystemReport::bytes($file['before']),
                SystemReport::bytes($file['after']),
                $file['resized'] ? lang('Core.optimize.resized') : '-',
                $file['variants'],
            ]));
        }

        foreach ($summary['failed'] as $failed) {
            CLI::write(lang('Core.optimize.failed', [$failed['path'], $failed['error']]), 'red');
        }

        if ($summary['processed'] === 0 && $summary['failed'] === []) {
            CLI::write(lang('Core.optimize.none'), 'green');
        }

        CLI::write(self::summary($summary), $summary['failed'] === [] ? 'green' : 'yellow');

        if (! $dryRun && ($summary['processed'] > 0 || $summary['failed'] !== [])) {
            activity_log('Core', 'images_optimized', [
                'summary' => self::summary($summary),
                'after'   => ['via' => 'command', 'processed' => $summary['processed'], 'resized' => $summary['resized'], 'variants' => $summary['variants'], 'saved' => $summary['bytesBefore'] - $summary['bytesAfter'], 'failed' => count($summary['failed']), 'left' => $summary['left']],
            ]);
        }

        return EXIT_SUCCESS;
    }

    /**
     * @param array{processed: int, resized: int, variants: int, bytesBefore: int, bytesAfter: int, failed: list<array<string, mixed>>, left: int} $summary
     */
    public static function summary(array $summary): string
    {
        $saved = $summary['bytesBefore'] - $summary['bytesAfter'];

        return lang('Core.optimize.summary', [
            $summary['processed'],
            $summary['resized'],
            $summary['variants'],
            SystemReport::bytes($summary['bytesBefore']),
            SystemReport::bytes($summary['bytesAfter']),
            ($saved < 0 ? '+' : '') . SystemReport::bytes(abs($saved)),
            count($summary['failed']),
            $summary['left'],
        ]);
    }

    private static function option(string $name): ?string
    {
        $value = CLI::getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
