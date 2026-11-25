<?php

declare(strict_types=1);

namespace Modules\Backup\Services;

use Modules\Core\System\ReportRow;
use Modules\Core\System\SystemReport;
use Throwable;

/**
 * The Backup module's rows of the System information report (event `systemReport`,
 * docs/system.md): the last successful backup and its age. A warning when scheduled backups are
 * on and the last one is older than MAX_AGE_DAYS, or there is none.
 */
final class BackupSystemReport
{
    public const MAX_AGE_DAYS = 8;

    public function __construct(private readonly ?BackupRepository $repository = null)
    {
    }

    public function add(SystemReport $report, ?int $now = null): void
    {
        $section = $report->section('backup', lang('Backup.system.title'));

        try {
            $last = ($this->repository ?? new BackupRepository())->successful()[0] ?? null;
        } catch (Throwable) {
            $section->add(lang('Backup.system.last'), lang('System.values.unknown'));

            return;
        }

        $scheduled = (bool) setting('Backup.scheduled');

        if ($last === null) {
            $section->add(lang('Backup.system.last'), lang('System.values.never'), $scheduled ? ReportRow::WARNING : null, lang('Backup.system.none'));

            return;
        }

        $created = (string) ($last['created_at'] ?? '');
        $days    = (int) floor((($now ?? time()) - (int) strtotime($created . ' UTC')) / 86400);
        $old     = $scheduled && $days > self::MAX_AGE_DAYS;

        $section->add(
            lang('Backup.system.last'),
            lang('Backup.system.age', [$created . ' UTC', (string) max(0, $days)]),
            $old ? ReportRow::WARNING : ReportRow::OK,
            $old ? lang('Backup.system.old', [(string) self::MAX_AGE_DAYS]) : '',
        );
    }
}
