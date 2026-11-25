<?php

declare(strict_types=1);

namespace Modules\Core\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Storage\UnusedFiles;
use Modules\Core\Storage\UnusedFilesException;
use Modules\Core\System\SystemReport;

/**
 * php spark storage:unused [--module=<Name>] [--older-than=<days>] [--delete] [--yes] [--limit=N]
 *
 * Lists the stored files of the module spaces that no module references and that are older than
 * the grace period (D-55-01, docs/storage.md "Unused files"), after the folders that are "not
 * checked" (no enabled module answers for them; never touched). `--delete` deletes them after a
 * confirmation (`--yes` skips it), re-checking every file first, and writes one activity entry.
 * `--older-than` can only make the grace period longer.
 */
final class StorageUnused extends BaseCommand
{
    protected $group       = 'Application';
    protected $name        = 'storage:unused';
    protected $description = 'Lists (and with --delete deletes) the stored files of the module folders that nothing uses.';
    protected $usage       = 'storage:unused [--module=<Name>] [--older-than=<days>] [--delete] [--yes] [--limit=N]';

    /** @var array<string, string> */
    protected $options = [
        '--module'     => 'Only the folders of this module.',
        '--older-than' => 'Only files older than this many days (never below the grace period).',
        '--delete'     => 'Delete the files listed (asks first).',
        '--yes'        => 'Do not ask before deleting.',
        '--limit'      => 'List or delete at most N files.',
    ];

    /**
     * @param array<int|string, string|null> $params
     *
     * @return int
     */
    public function run(array $params)
    {
        $module    = self::option($params, 'module');
        $olderThan = self::option($params, 'older-than');
        $limit     = self::option($params, 'limit');
        $delete    = CLI::getOption('delete') !== null || array_key_exists('delete', $params);
        $yes       = CLI::getOption('yes') !== null || array_key_exists('yes', $params);
        $grace     = UnusedFiles::graceDays();

        if ($olderThan !== null && ! ctype_digit($olderThan)) {
            CLI::error(lang('Core.unused.badOlderThan'));

            return EXIT_ERROR;
        }

        if ($olderThan !== null && (int) $olderThan < $grace) {
            CLI::write(lang('Core.unused.olderThanRaised', [$olderThan, $grace]), 'yellow');
        }

        if ($limit !== null && (! ctype_digit($limit) || (int) $limit < 1)) {
            CLI::error(lang('Core.optimize.badLimit'));

            return EXIT_ERROR;
        }

        $unused = new UnusedFiles();

        try {
            $scan = $unused->scan(['module' => $module, 'olderThan' => $olderThan === null ? null : (int) $olderThan, 'limit' => $limit === null ? null : (int) $limit]);
        } catch (UnusedFilesException $e) {
            CLI::error(lang('Core.unused.scanFailed', [$e->getMessage()]));

            return EXIT_ERROR;
        }

        if ($module !== null && ! in_array(strtolower($module), array_map(static fn (array $c): string => strtolower($c['module']), $scan['checked']), true)) {
            CLI::write(lang('Core.unused.moduleNotChecked', [$module]), 'yellow');
        }

        CLI::write(lang('Core.unused.cliGrace', [$scan['olderThan'], $scan['graceDays']]));

        foreach ($scan['notChecked'] as $folder) {
            CLI::write(lang('Core.unused.cliNotChecked', [
                $folder['disk'] . ':' . $folder['folder'],
                $folder['files'],
                SystemReport::bytes($folder['bytes']),
                lang('Core.unused.reason.' . $folder['reason'], [$folder['module'] ?? '']),
            ]), 'yellow');
        }

        foreach ($scan['files'] as $file) {
            CLI::write(lang('Core.unused.cliFile', [
                $file['disk'] . ':' . $file['path'],
                $file['module'],
                SystemReport::bytes($file['size'] + $file['variantsBytes']),
                substr($file['createdAt'], 0, 10),
                $file['name'],
            ]));
        }

        $listed = count($scan['files']);
        CLI::write(lang('Core.unused.cliSummary', [$scan['count'], SystemReport::bytes($scan['bytes']), $scan['variants']]), $scan['count'] === 0 ? 'green' : 'yellow');

        if ($scan['truncated']) {
            CLI::write(lang('Core.unused.cliTruncated', [$listed, $scan['count']]));
        }

        if (! $delete || $listed === 0) {
            return EXIT_SUCCESS;
        }

        if (! $yes && CLI::prompt(lang('Core.unused.cliConfirm', [$listed]), ['n', 'y']) !== 'y') {
            CLI::write(lang('Core.unused.cliCancelled'));

            return EXIT_SUCCESS;
        }

        try {
            $result = $unused->delete(array_map(static fn (array $file): int => (int) $file['id'], $scan['files']), [
                'olderThan' => $scan['olderThan'],
                'module'    => $module,
                'via'       => 'command',
            ]);
        } catch (UnusedFilesException $e) {
            CLI::error(lang('Core.unused.scanFailed', [$e->getMessage()]));

            return EXIT_ERROR;
        }

        CLI::write(lang('Core.unused.deleted', [$result['deleted'], SystemReport::bytes($result['bytes']), UnusedFiles::skippedCount($result)]), 'green');

        return EXIT_SUCCESS;
    }

    /**
     * An option's value, written `--name value` or `--name=value` (CodeIgniter's parser keeps
     * `name=value` as the option's name).
     *
     * @param array<int|string, string|null> $params
     */
    private static function option(array $params, string $name): ?string
    {
        $options = $params + CLI::getOptions();

        foreach ($options as $key => $value) {
            if ($key === $name && is_string($value) && $value !== '') {
                return $value;
            }

            if (is_string($key) && str_starts_with($key, $name . '=') && strlen($key) > strlen($name) + 1) {
                return substr($key, strlen($name) + 1);
            }
        }

        return null;
    }
}
