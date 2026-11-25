<?php
/**
 * Settings › System › Error logs (`backend/system/logs`; docs/system.md): the files of
 * `writable/logs`, newest first. Read only (D-53-01): no download, no delete.
 *
 * @var string                                                                    $layout
 * @var list<array{date: string, name: string, size: int, modified: int}>         $files
 */
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<p class="text-body-secondary"><?= esc(lang('System.logs.intro')) ?></p>
<?php if ($files === []): ?>
    <?= theme_component('empty_state', ['icon' => 'bi bi-journal-text', 'title' => lang('System.logs.empty'), 'message' => null, 'action' => null, 'compact' => false]) ?>
<?php else: ?>
    <?php ob_start() ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" data-log-files>
            <thead>
                <tr>
                    <th scope="col"><?= esc(lang('System.logs.date')) ?></th>
                    <th scope="col"><?= esc(lang('System.logs.file')) ?></th>
                    <th scope="col" class="text-end"><?= esc(lang('System.logs.size')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $file): ?>
                    <tr data-log-date="<?= esc($file['date'], 'attr') ?>">
                        <th scope="row" class="fw-normal"><a href="<?= esc(backend_url('system/logs/' . $file['date']), 'attr') ?>"><?= esc($file['date']) ?></a></th>
                        <td class="font-monospace small"><?= esc($file['name']) ?></td>
                        <td class="text-end"><?= esc(ui_bytes($file['size'])) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php $body = (string) ob_get_clean() ?>
    <?= theme_component('card', ['title' => lang('System.logs.title'), 'icon' => 'bi bi-journal-text', 'body' => $body]) ?>
<?php endif ?>
<?= $this->endSection() ?>
