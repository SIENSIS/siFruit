<?php
/**
 * Devtools › Routes (session 53): every route, filterable; problems marked.
 *
 * @var string                                                                                                                              $layout
 * @var list<array{method: string, path: string, name: string, handler: string, filters: list<string>, module: string, problem: string}>     $routes
 * @var int                                                                                                                                 $total
 * @var int                                                                                                                                 $problems
 * @var list<string>                                                                                                                        $modules
 * @var string                                                                                                                              $module
 * @var string                                                                                                                              $method
 * @var string                                                                                                                              $text
 * @var bool                                                                                                                                $flagged
 */
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?= $this->include('Modules\Devtools\Views\_banner') ?>
<?= $this->setVar('active', 'routes')->include('Modules\Devtools\Views\_nav') ?>
<p class="text-body-secondary"><?= esc(lang('Devtools.routes.intro')) ?></p>
<form method="get" action="<?= esc(backend_url('devtools/routes'), 'attr') ?>" class="row g-2 align-items-end mb-3" role="search">
    <div class="col-6 col-md-3">
        <label class="form-label" for="routes-module"><?= esc(lang('Core.list.module')) ?></label>
        <select class="form-select" id="routes-module" name="module">
            <option value=""><?= esc(lang('Devtools.routes.all')) ?></option>
            <?php foreach ($modules as $option): ?>
                <option value="<?= esc($option, 'attr') ?>"<?= $option === $module ? ' selected' : '' ?>><?= esc($option) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label" for="routes-method"><?= esc(lang('Devtools.routes.method')) ?></label>
        <select class="form-select" id="routes-method" name="method">
            <option value=""><?= esc(lang('Devtools.routes.all')) ?></option>
            <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CLI'] as $option): ?>
                <option value="<?= $option ?>"<?= $option === $method ? ' selected' : '' ?>><?= $option ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label" for="routes-text"><?= esc(lang('Devtools.routes.text')) ?></label>
        <input type="search" class="form-control" id="routes-text" name="q" value="<?= esc($text, 'attr') ?>" maxlength="100">
    </div>
    <div class="col-12 col-md-2">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="routes-problems" name="problems" value="1"<?= $flagged ? ' checked' : '' ?>>
            <label class="form-check-label" for="routes-problems"><?= esc(lang('Devtools.routes.onlyProblems')) ?></label>
        </div>
    </div>
    <div class="col-12 col-md-2"><button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-funnel me-1" aria-hidden="true"></i><?= esc(lang('Devtools.routes.filter')) ?></button></div>
</form>
<p class="small" data-routes-count="<?= count($routes) ?>" data-routes-problems="<?= $problems ?>"><?= esc(lang('Devtools.routes.count', [count($routes), $total, $problems])) ?></p>
<div class="table-responsive">
    <table class="table table-sm small align-middle" data-routes>
        <thead>
            <tr>
                <th scope="col"><?= esc(lang('Devtools.routes.method')) ?></th>
                <th scope="col"><?= esc(lang('Devtools.routes.path')) ?></th>
                <th scope="col"><?= esc(lang('Devtools.routes.name')) ?></th>
                <th scope="col"><?= esc(lang('Devtools.routes.handler')) ?></th>
                <th scope="col"><?= esc(lang('Devtools.routes.filters')) ?></th>
                <th scope="col"><?= esc(lang('Core.list.module')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($routes as $route): ?>
                <tr<?= $route['problem'] !== '' ? ' class="table-warning" data-route-problem="' . esc($route['problem'], 'attr') . '"' : '' ?>>
                    <td><?= ui_badge($route['method'], $route['method'] === 'GET' ? 'secondary' : 'primary') ?></td>
                    <td class="font-monospace text-break"><?= esc($route['path']) ?>
                        <?php if ($route['problem'] !== ''): ?><div class="text-danger-emphasis"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><?= esc($route['problem']) ?></div><?php endif ?>
                    </td>
                    <td class="font-monospace"><?= esc($route['name']) ?></td>
                    <td class="font-monospace text-break"><?= esc($route['handler']) ?></td>
                    <td class="font-monospace text-break"><?= esc(implode(', ', $route['filters'])) ?></td>
                    <td><?= esc($route['module']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
