<?php
/**
 * Devtools › Module inspector: every discovered module (session 53).
 *
 * @var string                                                                                                                                                  $layout
 * @var list<array{name: string, version: string, installed: string|null, enabled: bool|null, developmentOnly: bool, valid: bool, description: string}>        $modules
 */
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?= $this->include('Modules\Devtools\Views\_banner') ?>
<?= $this->setVar('active', 'inspector')->include('Modules\Devtools\Views\_nav') ?>
<p class="text-body-secondary"><?= esc(lang('Devtools.inspector.intro')) ?></p>
<?php ob_start() ?>
<div class="table-responsive">
    <table class="table table-sm align-middle mb-0" data-inspector-modules>
        <thead>
            <tr>
                <th scope="col"><?= esc(lang('Core.list.module')) ?></th>
                <th scope="col"><?= esc(lang('Core.list.version')) ?></th>
                <th scope="col"><?= esc(lang('Core.list.installed')) ?></th>
                <th scope="col"><?= esc(lang('Core.list.enabled')) ?></th>
                <th scope="col" class="d-none d-md-table-cell"><?= esc(lang('Devtools.inspector.description')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($modules as $module): ?>
                <tr data-module="<?= esc($module['name'], 'attr') ?>">
                    <th scope="row"><a href="<?= esc(backend_url('devtools/modules/' . $module['name']), 'attr') ?>"><?= esc($module['name']) ?></a>
                        <?php if ($module['developmentOnly']): ?> <?= ui_badge(lang('System.rows.developmentOnly'), 'warning') ?><?php endif ?>
                        <?php if (! $module['valid']): ?> <?= ui_badge(lang('Devtools.inspector.invalid'), 'danger') ?><?php endif ?>
                    </th>
                    <td><?= esc($module['version']) ?></td>
                    <td><?= esc($module['installed'] ?? '—') ?></td>
                    <td><?= $module['enabled'] === null ? '—' : ui_badge(lang($module['enabled'] ? 'Core.list.yes' : 'Core.list.no'), $module['enabled'] ? 'success' : 'secondary') ?></td>
                    <td class="d-none d-md-table-cell small text-body-secondary"><?= esc($module['description']) ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php $body = (string) ob_get_clean() ?>
<?= theme_component('card', ['title' => lang('Devtools.inspector.title'), 'icon' => 'bi bi-boxes', 'body' => $body]) ?>
<?= $this->endSection() ?>
