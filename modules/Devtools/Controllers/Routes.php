<?php

declare(strict_types=1);

namespace Modules\Devtools\Controllers;

use Modules\Core\Controllers\BackendController;
use Modules\Devtools\Inspector\RouteInventory;

/**
 * Devtools › Routes (`backend/devtools/routes`, `devtools.view`; session 53): every route with
 * its method, path, name, handler, filters and module, filterable, with the routes that lack
 * `can:`, kpapy or CSRF marked (docs/devtools.md).
 */
final class Routes extends BackendController
{
    public function index(): string
    {
        $routes  = (new RouteInventory())->all();
        $module  = $this->request->getGet('module');
        $method  = $this->request->getGet('method');
        $text    = $this->request->getGet('q');
        $flagged = $this->request->getGet('problems') === '1';
        $modules = array_values(array_unique(array_filter(array_column($routes, 'module'))));
        sort($modules);
        $module = is_string($module) && in_array($module, $modules, true) ? $module : '';
        $method = is_string($method) && in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD', 'CLI'], true) ? $method : '';
        $text   = is_string($text) ? mb_substr(trim($text), 0, 100) : '';

        $shown = array_values(array_filter($routes, static fn (array $r): bool => ($module === '' || $r['module'] === $module)
            && ($method === '' || $r['method'] === $method)
            && (! $flagged || $r['problem'] !== '')
            && ($text === '' || stripos($r['path'] . ' ' . $r['name'] . ' ' . $r['handler'] . ' ' . implode(' ', $r['filters']), $text) !== false)));

        $this->breadcrumb(lang('Devtools.title'), backend_url('devtools'));
        $this->breadcrumb(lang('Devtools.routes.title'));

        return $this->render('Modules\Devtools\Views\routes', [
            'title'    => lang('Devtools.routes.title'),
            'icon'     => 'bi bi-signpost-split',
            'routes'   => $shown,
            'total'    => count($routes),
            'problems' => count(array_filter($routes, static fn (array $r): bool => $r['problem'] !== '')),
            'modules'  => $modules,
            'module'   => $module,
            'method'   => $method,
            'text'     => $text,
            'flagged'  => $flagged,
        ]);
    }
}
