<?php

declare(strict_types=1);

namespace Modules\Core\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\Search\SearchIndex;

/**
 * php spark search:reindex [<Module>]
 *
 * Rebuilds the site search index (D-50-01, docs/search.md) from the `SearchSource` of every search
 * source of the enabled modules, or of one module; a full rebuild also drops the documents of
 * sources nobody declares any more. `modules:sync` runs it too.
 */
final class SearchReindex extends BaseCommand
{
    protected $group       = 'Application';
    protected $name        = 'search:reindex';
    protected $description = 'Rebuilds the site search index from the search sources of the enabled modules (or of one module).';
    protected $usage       = 'search:reindex [<Module>]';

    /** @var array<string, string> */
    protected $arguments = ['Module' => 'Only the sources of this module (default: every enabled module).'];

    /**
     * @param array<int|string, string|null> $params
     *
     * @return int
     */
    public function run(array $params)
    {
        $first  = $params[0] ?? null;
        $module = $first === null || $first === '' ? null : $first;

        if ($module !== null && preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $module) !== 1) {
            CLI::error(lang('Core.search.reindexBadModule', [$module]));

            return EXIT_ERROR;
        }

        /** @var SearchIndex $index */
        $index  = service('search');
        $counts = $index->reindex($module);

        if ($counts === []) {
            CLI::write(lang('Core.search.reindexNone'), 'yellow');

            return EXIT_SUCCESS;
        }

        foreach ($counts as $source => $count) {
            CLI::write(lang('Core.search.reindexed', [$source, $count]), 'green');
        }

        return EXIT_SUCCESS;
    }
}
