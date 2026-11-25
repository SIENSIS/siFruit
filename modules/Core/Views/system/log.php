<?php
/**
 * One log file of Settings › System › Error logs (docs/system.md): its entries newest first,
 * a bounded chunk per page, filterable by level and text, masked (LogRedactor) and escaped.
 *
 * @var string                                                                                                                                                       $layout
 * @var string                                                                                                                                                       $date
 * @var array{entries: list<array{level: string, date: string, message: string, trace: list<string>, traceLines: int}>, older: int|null, size: int, bytesRead: int} $page
 * @var string                                                                                                                                                       $level
 * @var string                                                                                                                                                       $text
 * @var string                                                                                                                                                       $base
 * @var string|null                                                                                                                                                  $older
 * @var string|null                                                                                                                                                  $newest
 */
$variants = ['emergency' => 'danger', 'alert' => 'danger', 'critical' => 'danger', 'error' => 'danger', 'warning' => 'warning', 'notice' => 'info', 'info' => 'info', 'debug' => 'secondary'];
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<p><?= ui_link(lang('System.logs.back'), backend_url('system/logs'), 'bi bi-arrow-left') ?></p>
<form method="get" action="<?= esc($base, 'attr') ?>" class="row g-2 align-items-end mb-3" role="search">
    <div class="col-12 col-sm-4 col-lg-3">
        <label class="form-label" for="log-level"><?= esc(lang('System.logs.level')) ?></label>
        <select class="form-select" id="log-level" name="level">
            <option value=""><?= esc(lang('System.logs.allLevels')) ?></option>
            <?php foreach (Modules\Core\System\LogFiles::LEVELS as $option): ?>
                <option value="<?= esc($option, 'attr') ?>"<?= $option === $level ? ' selected' : '' ?>><?= esc(strtoupper($option)) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-12 col-sm-5 col-lg-4">
        <label class="form-label" for="log-text"><?= esc(lang('System.logs.search')) ?></label>
        <input class="form-control" type="search" id="log-text" name="q" maxlength="100" value="<?= esc($text, 'attr') ?>">
    </div>
    <div class="col-12 col-sm-3 col-lg-2">
        <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-funnel me-1" aria-hidden="true"></i><?= esc(lang('System.logs.filter')) ?></button>
    </div>
</form>
<?php if ($page['size'] > Modules\Core\System\LogFiles::CHUNK): ?>
    <p class="small text-body-secondary"><?= esc(lang('System.logs.truncated')) ?> (<?= esc(ui_bytes($page['size'])) ?>)</p>
<?php endif ?>
<?php if ($page['entries'] === []): ?>
    <?= theme_component('empty_state', ['icon' => 'bi bi-search', 'title' => lang('System.logs.noEntries'), 'message' => null, 'action' => null, 'compact' => true]) ?>
<?php else: ?>
    <ol class="list-unstyled mb-3" data-log-entries>
        <?php foreach ($page['entries'] as $entry): ?>
            <li class="border rounded p-2 mb-2" data-log-level="<?= esc($entry['level'], 'attr') ?>">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                    <?= ui_badge(strtoupper($entry['level']), $variants[$entry['level']] ?? 'secondary') ?>
                    <span class="small text-body-secondary font-monospace"><?= esc($entry['date']) ?></span>
                </div>
                <div class="font-monospace small text-break" data-log-message><?= esc($entry['message']) ?></div>
                <?php if ($entry['trace'] !== []): ?>
                    <details class="mt-1">
                        <summary class="small"><?= esc(lang('System.logs.trace', [$entry['traceLines']])) ?></summary>
                        <pre tabindex="0" class="small mb-0 mt-1 text-break"><?= esc(implode("\n", $entry['trace'])) ?></pre>
                    </details>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
<?php endif ?>
<nav class="d-flex flex-wrap gap-2" aria-label="<?= esc(lang('System.logs.title'), 'attr') ?>">
    <?php if ($newest !== null): ?>
        <a class="btn btn-outline-secondary" href="<?= esc($newest, 'attr') ?>"><i class="bi bi-chevron-double-left me-1" aria-hidden="true"></i><?= esc(lang('System.logs.newer')) ?></a>
    <?php endif ?>
    <?php if ($older !== null): ?>
        <a class="btn btn-outline-secondary" href="<?= esc($older, 'attr') ?>" data-log-older><?= esc(lang('System.logs.older')) ?><i class="bi bi-chevron-right ms-1" aria-hidden="true"></i></a>
    <?php endif ?>
</nav>
<?= $this->endSection() ?>
