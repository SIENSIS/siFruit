<?php

declare(strict_types=1);

namespace Modules\Core\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\System\LogFiles;
use Modules\Core\System\SystemReport;

/**
 * Settings › System › System information (`backend/system`, `system.info`; D-53-01,
 * docs/system.md): the report of `php spark about` plus the server's health, a plain-text copy
 * for support (copy button and download, logged) and "Refresh" (drops the cached folder sizes
 * and assets check). Opening the screen is not logged.
 */
final class System extends BackendController
{
    public function index(): string
    {
        $report = SystemReport::build();
        $logs   = null;

        if ($this->can('system.logs')) {
            $today = gmdate('Y-m-d');
            $logs  = ['errors' => (new LogFiles())->errorCount($today), 'url' => backend_url('system/logs')];
        }

        if ($this->can('system.files')) {
            $report->section('storage')->link ??= ['url' => backend_url('system/unused-files'), 'label' => lang('System.unused.title')];
        }

        $this->breadcrumb(lang('System.info.title'));

        return $this->render('Modules\Core\Views\system\index', [
            'title'  => lang('System.info.title'),
            'icon'   => 'bi bi-info-circle',
            'report' => $report,
            'logs'   => $logs,
        ]);
    }

    public function download(): ResponseInterface
    {
        $text = SystemReport::build()->plain();
        activity_log('Core', 'system_report_downloaded', ['resource' => 'system']);

        return $this->response
            ->setHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="system-report-' . gmdate('Y-m-d') . '.txt"')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($text);
    }

    public function refresh(): RedirectResponse
    {
        SystemReport::refresh();

        return redirect()->to(backend_url('system'))->with('success', lang('System.info.refreshed'));
    }
}
