<?php

declare(strict_types=1);

namespace Modules\Devtools\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use Modules\Core\Controllers\BackendController;
use Modules\Devtools\Inspector\Markdown;
use Modules\Devtools\Inspector\ModuleInspector;

/**
 * Devtools › Module inspector (`backend/devtools/modules`, `devtools.view`; session 53): every
 * discovered module and, for one, its manifest as the Core resolves it (docs/devtools.md).
 * Read only.
 */
final class Modules extends BackendController
{
    public function index(): string
    {
        $this->breadcrumb(lang('Devtools.title'), backend_url('devtools'));
        $this->breadcrumb(lang('Devtools.inspector.title'));

        return $this->render('Modules\Devtools\Views\modules\index', [
            'title'   => lang('Devtools.inspector.title'),
            'icon'    => 'bi bi-search',
            'modules' => (new ModuleInspector())->list(),
        ]);
    }

    public function show(string $name): string
    {
        $report = preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) === 1 ? (new ModuleInspector())->inspect($name) : null;

        if ($report === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->breadcrumb(lang('Devtools.title'), backend_url('devtools'));
        $this->breadcrumb(lang('Devtools.inspector.title'), backend_url('devtools/modules'));
        $this->breadcrumb($name);

        return $this->render('Modules\Devtools\Views\modules\show', [
            'title'  => lang('Devtools.inspector.title') . ' · ' . $name,
            'icon'   => 'bi bi-search',
            'report' => $report,
            'claude' => is_string($report['claude'] ?? null) ? Markdown::toHtml($report['claude']) : null,
        ]);
    }
}
