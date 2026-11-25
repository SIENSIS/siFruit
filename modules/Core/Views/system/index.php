<?php
/**
 * Settings › System › System information (`backend/system`; docs/system.md): the sections of
 * the shared report (the same as `php spark about`), problems marked with ui_badge(), the
 * plain-text copy for support (copy button, download) and "Refresh".
 *
 * @var string                                                $layout
 * @var Modules\Core\System\SystemReport                      $report
 * @var array{errors: int, url: string}|null                  $logs
 */
$counts   = $report->counts();
$variants = ['ok' => 'success', 'warning' => 'warning', 'error' => 'danger'];
$icons    = ['ok' => 'bi bi-check-circle', 'warning' => 'bi bi-exclamation-triangle', 'error' => 'bi bi-x-octagon'];
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-12">
        <?php ob_start() ?>
        <p class="text-body-secondary"><?= esc(lang('System.info.intro')) ?></p>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-system-summary data-warnings="<?= $counts['warning'] ?>" data-errors="<?= $counts['error'] ?>">
            <?php if ($counts['warning'] + $counts['error'] === 0): ?>
                <?= ui_badge(lang('System.info.noProblems'), 'success', 'bi bi-check-circle') ?>
            <?php else: ?>
                <?= ui_badge(lang('System.info.problems', [$counts['warning'], $counts['error']]), $counts['error'] > 0 ? 'danger' : 'warning', 'bi bi-exclamation-triangle') ?>
            <?php endif ?>
            <?php if ($logs !== null): ?>
                <a class="small" href="<?= esc($logs['url'], 'attr') ?>" data-system-log-errors="<?= $logs['errors'] ?>"><?= esc(lang('System.rows.logErrors')) ?>: <?= $logs['errors'] ?></a>
            <?php endif ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary" data-component="core/ui/copy" data-copy-target="system-report-text" data-options="<?= esc(json_encode(['copied' => lang('System.info.copied')]), 'attr') ?>"><i class="bi bi-clipboard me-1" aria-hidden="true"></i><?= esc(lang('System.info.copy')) ?></button>
            <a class="btn btn-outline-secondary" href="<?= esc(backend_url('system/report.txt'), 'attr') ?>" download><i class="bi bi-download me-1" aria-hidden="true"></i><?= esc(lang('System.info.download')) ?></a>
            <form action="<?= esc(backend_url('system/refresh'), 'attr') ?>" method="post">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i><?= esc(lang('System.info.refresh')) ?></button>
            </form>
        </div>
        <?php $body = (string) ob_get_clean() ?>
        <?= theme_component('card', ['title' => lang('System.info.title'), 'icon' => 'bi bi-info-circle', 'body' => $body]) ?>
    </div>
    <?php foreach ($report->sections() as $section): ?>
        <div class="col-12<?= $section->key === 'modules' ? '' : ' col-xl-6' ?>" data-system-section="<?= esc($section->key, 'attr') ?>">
            <?php ob_start() ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <?php foreach ($section->headers as $header): ?>
                                <th scope="col"><?= esc($header) ?></th>
                            <?php endforeach ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($section->rows() as $row): ?>
                            <tr<?= $row->isProblem() ? ' data-status="' . esc((string) $row->status, 'attr') . '"' : '' ?>>
                                <?php foreach ($row->cells as $i => $cell): ?>
                                    <?php if ($i === 0): ?>
                                        <th scope="row" class="fw-normal text-break"><?= esc($cell) ?></th>
                                    <?php else: ?>
                                        <td class="text-break">
                                            <?= esc($cell) ?>
                                            <?php if ($i === count($row->cells) - 1 && $row->status !== null): ?>
                                                <?= ui_badge(Modules\Core\System\SystemReport::statusLabel($row->status), $variants[$row->status] ?? 'secondary', $icons[$row->status] ?? null) ?>
                                            <?php endif ?>
                                            <?php if ($i === count($row->cells) - 1 && $row->isProblem() && $row->note !== ''): ?>
                                                <div class="small text-body-secondary"><?= esc($row->note) ?></div>
                                            <?php endif ?>
                                        </td>
                                    <?php endif ?>
                                <?php endforeach ?>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php if ($section->link !== null): ?>
                <p class="mt-2 mb-0"><?= ui_link($section->link['label'], $section->link['url'], 'bi bi-arrow-right') ?></p>
            <?php endif ?>
            <?php $body = (string) ob_get_clean() ?>
            <?= theme_component('card', ['title' => $section->title, 'body' => $body]) ?>
        </div>
    <?php endforeach ?>
    <div class="col-12">
        <details>
            <summary><?= esc(lang('System.info.plainTitle')) ?></summary>
            <label class="visually-hidden" for="system-report-text"><?= esc(lang('System.info.plainTitle')) ?></label>
            <textarea id="system-report-text" class="form-control font-monospace small mt-2" rows="12" readonly><?= esc($report->plain()) ?></textarea>
        </details>
    </div>
</div>
<?= $this->endSection() ?>
