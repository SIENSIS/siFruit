<?php

declare(strict_types=1);

namespace Modules\Core\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Modules\Core\System\SystemReport;

/**
 * php spark about [--plain]
 *
 * The application, its environment and its modules at a glance (for support requests): the
 * System information report (`Modules\Core\System\SystemReport`, the same one Settings › System
 * shows, D-53-01). Never prints secrets: keys are only reported as set or not set. `--plain`
 * prints the text of "Copy report for support".
 */
final class About extends BaseCommand
{
    protected $group       = 'Application';
    protected $name        = 'about';
    protected $description = 'Shows the versions, the environment, the database, the caches and the modules of the application.';
    protected $usage       = 'about [--plain]';
    protected $options     = [
        '--plain' => 'Print the plain-text report of Settings › System (the text "Copy report for support" copies).',
    ];

    /**
     * @param array<int|string, string|null> $params
     *
     * @return int
     */
    public function run(array $params)
    {
        $report = SystemReport::build();

        if (array_key_exists('plain', $params) || CLI::getOption('plain') !== null) {
            CLI::print($report->plain());

            return EXIT_SUCCESS;
        }

        foreach ($report->sections() as $section) {
            CLI::write($section->title, 'green');
            $rows = [];

            foreach ($section->rows() as $row) {
                $cells = $row->cells;

                if ($row->isProblem()) {
                    $last         = count($cells) - 1;
                    $cells[$last] .= ' [' . SystemReport::statusLabel((string) $row->status) . ']';
                }

                $rows[] = $cells;
            }

            CLI::table($rows, $section->headers);
        }

        return EXIT_SUCCESS;
    }
}
