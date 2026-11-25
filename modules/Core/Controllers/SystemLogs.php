<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use Modules\Core\System\LogFiles;

/**
 * Settings › System › Error logs (`backend/system/logs`, `system.logs` — superadmins only —,
 * re-authentication, a rate limit; D-53-01, docs/system.md). Read only: no download, no delete.
 * A file is chosen by its date; its entries are shown newest first, a bounded chunk per page,
 * masked by LogRedactor and escaped. Each opening is written to the activity log.
 */
final class SystemLogs extends BackendController
{
    public function index(): string
    {
        $this->breadcrumb(lang('System.logs.title'));

        return $this->render('Modules\Core\Views\system\logs', [
            'title' => lang('System.logs.title'),
            'icon'  => 'bi bi-journal-text',
            'files' => (new LogFiles())->files(),
        ]);
    }

    public function show(string $date): string
    {
        $logs    = new LogFiles();
        $request = $this->request;
        $before  = $request->getGet('before');
        $level   = $request->getGet('level');
        $text    = $request->getGet('q');
        $before  = is_string($before) && ctype_digit($before) ? (int) $before : null;
        $level   = is_string($level) && in_array($level, LogFiles::LEVELS, true) ? $level : '';
        $text    = is_string($text) ? mb_substr(trim($text), 0, 100) : '';
        $page    = $logs->read($date, $before, $level, $text);

        if ($page === null) {
            throw PageNotFoundException::forPageNotFound(lang('System.logs.notFound'));
        }

        if ($before === null) {
            activity_log('Core', 'system_log_opened', ['resource' => 'system', 'summary' => 'log-' . $date . '.log']);
        }

        $this->breadcrumb(lang('System.logs.title'), backend_url('system/logs'));
        $this->breadcrumb($date);

        $query = array_filter(['level' => $level, 'q' => $text], static fn (string $v): bool => $v !== '');

        return $this->render('Modules\Core\Views\system\log', [
            'title'  => lang('System.logs.title') . ' · ' . $date,
            'icon'   => 'bi bi-journal-text',
            'date'   => $date,
            'page'   => $page,
            'level'  => $level,
            'text'   => $text,
            'first'  => $before === null,
            'base'   => backend_url('system/logs/' . $date),
            'older'  => $page['older'] === null ? null : backend_url('system/logs/' . $date) . '?' . http_build_query($query + ['before' => $page['older']]),
            'newest' => $before === null ? null : backend_url('system/logs/' . $date) . ($query === [] ? '' : '?' . http_build_query($query)),
        ]);
    }
}
