<?php
/**
 * Settings › System › Unused files (`backend/system/unused-files`, `system.files`; D-55-01,
 * docs/system.md): the last scan, "Scan now", the folders that are not checked and the unused
 * files (preview, module, folder, name, size, date), deleted after re-authentication and a
 * confirmation. A plain form: `core/ui/unused-files` only adds "select all" and the totals of the
 * selection.
 *
 * @var string                                     $layout
 * @var array<string, mixed>|null                  $report
 * @var string|null                                $error
 * @var list<string>                               $modules
 * @var string|null                                $module
 * @var list<array<string, mixed>>                 $files
 * @var int                                        $total
 * @var int                                        $bytes
 * @var int                                        $page
 * @var int                                        $pages
 * @var int                                        $graceDays
 * @var array{enabled: bool, days: int}            $auto
 * @var string|null                                $backupsUrl
 * @var string                                     $baseUrl
 */
$query = static fn (array $params): string => $baseUrl . (($params = array_filter($params, static fn ($v): bool => $v !== null && $v !== 1)) === [] ? '' : '?' . http_build_query($params));
$size  = ui_bytes($bytes);
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<link rel="stylesheet" href="<?= esc(asset('core::css/unused-files.css'), 'attr') ?>">
<div class="row g-3">
    <div class="col-12">
        <?php ob_start() ?>
        <p class="text-body-secondary"><?= esc(lang('System.unused.intro')) ?></p>
        <div class="alert alert-warning d-flex gap-2" role="note">
            <i class="bi bi-exclamation-triangle mt-1" aria-hidden="true"></i>
            <div>
                <?= esc(lang('System.unused.backupFirst')) ?>
                <?php if ($backupsUrl !== null): ?>
                    <?= ui_link(lang('System.unused.backups'), $backupsUrl, 'bi bi-arrow-right') ?>
                <?php endif ?>
            </div>
        </div>
        <dl class="row mb-3" data-unused-summary>
            <dt class="col-sm-5 col-lg-4"><?= esc(lang('System.unused.grace')) ?></dt>
            <dd class="col-sm-7 col-lg-8" data-unused-grace="<?= $graceDays ?>"><?= esc(lang('System.unused.days', [$graceDays])) ?></dd>
            <dt class="col-sm-5 col-lg-4"><?= esc(lang('System.unused.lastScan')) ?></dt>
            <dd class="col-sm-7 col-lg-8"><?= $report === null ? '-' : ui_date((string) $report['scannedAt'], 'datetime') ?></dd>
            <dt class="col-sm-5 col-lg-4"><?= esc(lang('System.unused.auto')) ?></dt>
            <dd class="col-sm-7 col-lg-8" data-unused-auto="<?= $auto['enabled'] ? 'on' : 'off' ?>">
                <?= $auto['enabled'] ? ui_badge(lang('System.unused.autoOn', [$auto['days']]), 'warning', 'bi bi-clock-history') : ui_badge(lang('System.unused.autoOff'), 'secondary') ?>
            </dd>
        </dl>
        <form action="<?= esc(backend_url('system/unused-files/scan'), 'attr') ?>" method="post">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i><?= esc(lang('System.unused.scan')) ?></button>
        </form>
        <?php $body = (string) ob_get_clean() ?>
        <?= theme_component('card', ['title' => lang('System.unused.title'), 'icon' => 'bi bi-file-earmark-x', 'body' => $body]) ?>
    </div>

    <?php if ($error !== null): ?>
        <div class="col-12">
            <div class="alert alert-danger mb-0" role="alert" data-unused-error><?= esc(lang('Core.unused.scanFailed', [$error])) ?></div>
        </div>
    <?php endif ?>

    <?php if ($report !== null && $report['notChecked'] !== []): ?>
        <div class="col-12">
            <?php ob_start() ?>
            <p class="text-body-secondary"><?= esc(lang('System.unused.notCheckedIntro')) ?></p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" data-unused-not-checked>
                    <thead>
                        <tr>
                            <th scope="col"><?= esc(lang('System.unused.folder')) ?></th>
                            <th scope="col" class="text-end"><?= esc(lang('System.unused.files')) ?></th>
                            <th scope="col" class="text-end"><?= esc(lang('System.unused.size')) ?></th>
                            <th scope="col"><?= esc(lang('System.unused.why')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['notChecked'] as $folder): ?>
                            <tr>
                                <th scope="row" class="fw-normal font-monospace small text-break"><?= esc($folder['disk'] . ':' . $folder['folder']) ?></th>
                                <td class="text-end"><?= esc(number_format((int) $folder['files'])) ?></td>
                                <td class="text-end"><?= esc(ui_bytes((int) $folder['bytes'])) ?></td>
                                <td><?= esc(lang('Core.unused.reason.' . $folder['reason'], [(string) ($folder['module'] ?? '')])) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php $body = (string) ob_get_clean() ?>
            <?= theme_component('card', ['title' => lang('System.unused.notChecked'), 'icon' => 'bi bi-question-circle', 'body' => $body]) ?>
        </div>
    <?php endif ?>

    <?php if ($report !== null): ?>
        <div class="col-12">
            <?php ob_start() ?>
            <?php if ($modules !== []): ?>
                <form action="<?= esc($baseUrl, 'attr') ?>" method="get" class="d-flex flex-wrap align-items-end gap-2 mb-3">
                    <div>
                        <label class="form-label" for="unused-module"><?= esc(lang('System.unused.module')) ?></label>
                        <select class="form-select" id="unused-module" name="module">
                            <option value=""><?= esc(lang('System.unused.allModules')) ?></option>
                            <?php foreach ($modules as $name): ?>
                                <option value="<?= esc($name, 'attr') ?>"<?= $name === $module ? ' selected' : '' ?>><?= esc($name) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-outline-secondary"><?= esc(lang('System.unused.filter')) ?></button>
                </form>
            <?php endif ?>

            <?php if ($total === 0): ?>
                <?= theme_component('empty_state', ['icon' => 'bi bi-check2-circle', 'title' => lang('System.unused.empty'), 'message' => lang('System.unused.emptyHelp', [$graceDays]), 'action' => null, 'compact' => true]) ?>
            <?php else: ?>
                <p data-unused-total="<?= $total ?>" data-unused-bytes="<?= $bytes ?>"><strong><?= esc(lang('System.unused.total', [number_format($total), $size])) ?></strong><?= $report['truncated'] ? ' ' . esc(lang('System.unused.truncated', [number_format((int) $report['count'])])) : '' ?></p>
                <form action="<?= esc(backend_url('system/unused-files/delete'), 'attr') ?>" method="post" data-component="core/ui/unused-files" data-options="<?= esc(json_encode(['confirm' => lang('System.unused.confirmSelected'), 'selection' => lang('System.unused.selection'), 'units' => ['B', 'KB', 'MB', 'GB']]), 'attr') ?>">
                    <?= csrf_field() ?>
                    <?php if ($module !== null): ?>
                        <input type="hidden" name="module" value="<?= esc($module, 'attr') ?>">
                    <?php endif ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" data-unused-files>
                            <thead>
                                <tr>
                                    <th scope="col">
                                        <span data-unused-all-wrap hidden>
                                            <input class="form-check-input" type="checkbox" id="unused-all" data-unused-all hidden>
                                            <label class="visually-hidden" for="unused-all"><?= esc(lang('System.unused.selectAll')) ?></label>
                                        </span>
                                        <span class="visually-hidden"><?= esc(lang('System.unused.select')) ?></span>
                                    </th>
                                    <th scope="col"><?= esc(lang('System.unused.preview')) ?></th>
                                    <th scope="col"><?= esc(lang('System.unused.name')) ?></th>
                                    <th scope="col"><?= esc(lang('System.unused.module')) ?></th>
                                    <th scope="col" class="text-end"><?= esc(lang('System.unused.size')) ?></th>
                                    <th scope="col"><?= esc(lang('System.unused.date')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($files as $file): ?>
                                    <?php $fileBytes = (int) $file['size'] + (int) $file['variantsBytes'] ?>
                                    <tr data-unused-file="<?= (int) $file['id'] ?>">
                                        <td>
                                            <input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $file['id'] ?>" id="unused-<?= (int) $file['id'] ?>" data-bytes="<?= $fileBytes ?>">
                                            <label class="visually-hidden" for="unused-<?= (int) $file['id'] ?>"><?= esc(lang('System.unused.selectFile', [$file['name']])) ?></label>
                                        </td>
                                        <td>
                                            <?php if ($file['public'] && $file['image']): ?>
                                                <?= ui_image((int) $file['id'], ['alt' => '', 'sizes' => '64px', 'class' => 'kp-unused-thumb rounded border']) ?>
                                            <?php else: ?>
                                                <span class="kp-unused-icon d-inline-flex align-items-center justify-content-center rounded border text-body-secondary"><i class="bi bi-file-earmark<?= $file['image'] ? '-image' : '' ?>" aria-hidden="true"></i></span>
                                            <?php endif ?>
                                        </td>
                                        <td class="text-break">
                                            <?= esc($file['name']) ?>
                                            <div class="small text-body-secondary font-monospace"><?= esc($file['disk'] . ':' . $file['path']) ?></div>
                                        </td>
                                        <td><?= esc($file['module']) ?><div class="small text-body-secondary"><?= esc($file['folder']) ?></div></td>
                                        <td class="text-end text-nowrap">
                                            <?= esc(ui_bytes($fileBytes)) ?>
                                            <?php if ((int) $file['variants'] > 0): ?>
                                                <div class="small text-body-secondary"><?= esc(lang('System.unused.variants', [(int) $file['variants']])) ?></div>
                                            <?php endif ?>
                                        </td>
                                        <td class="text-nowrap"><?= ui_date((string) $file['createdAt']) ?></td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($pages > 1): ?>
                        <nav aria-label="<?= esc(lang('System.unused.pages'), 'attr') ?>" class="mb-3">
                            <ul class="pagination pagination-sm flex-wrap mb-0">
                                <?php for ($i = 1; $i <= $pages; $i++): ?>
                                    <li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= esc($query(['module' => $module, 'page' => $i]), 'attr') ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a></li>
                                <?php endfor ?>
                            </ul>
                        </nav>
                    <?php endif ?>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button type="submit" name="mode" value="selected" class="btn btn-danger" data-unused-delete-selected data-confirm="<?= esc(lang('System.unused.confirmSelectedPlain'), 'attr') ?>" data-confirm-variant="danger"><i class="bi bi-trash me-1" aria-hidden="true"></i><?= esc(lang('System.unused.deleteSelected')) ?></button>
                        <button type="submit" name="mode" value="all" class="btn btn-outline-danger" data-unused-delete-all data-confirm="<?= esc(lang('System.unused.confirmAll', [number_format($total), $size]), 'attr') ?>" data-confirm-variant="danger"><?= esc(lang('System.unused.deleteAll', [number_format($total)])) ?></button>
                        <span class="small text-body-secondary" data-unused-selection aria-live="polite"></span>
                    </div>
                </form>
            <?php endif ?>
            <?php $body = (string) ob_get_clean() ?>
            <?= theme_component('card', ['title' => lang('System.unused.listTitle'), 'icon' => 'bi bi-files', 'body' => $body]) ?>
        </div>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
