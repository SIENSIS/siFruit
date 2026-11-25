<?php
/**
 * Devtools › Checks (session 53): buttons that run the project's checks and show their output
 * and exit code (WRITE filters: re-authentication and the forms rate limit).
 *
 * @var string                                                                    $layout
 * @var list<string>                                                              $checks
 * @var array{check: string, command: string, status: int, output: string}|null   $result
 */
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?= $this->include('Modules\Devtools\Views\_banner') ?>
<?= $this->setVar('active', 'checks')->include('Modules\Devtools\Views\_nav') ?>
<p class="text-body-secondary"><?= esc(lang('Devtools.checks.intro')) ?></p>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($checks as $check): ?>
        <form method="post" action="<?= esc(backend_url('devtools/checks'), 'attr') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="check" value="<?= esc($check, 'attr') ?>">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-play me-1" aria-hidden="true"></i><?= esc(lang('Devtools.checks.names.' . $check)) ?></button>
        </form>
    <?php endforeach ?>
</div>
<?php if ($result !== null): ?>
    <?php ob_start() ?>
    <p class="mb-2"><code><?= esc($result['command']) ?></code> · <?= esc(lang('Devtools.checks.exit')) ?>: <?= ui_badge((string) $result['status'], $result['status'] === 0 ? 'success' : 'danger') ?></p>
    <pre tabindex="0" class="small mb-0 text-break bg-body-tertiary p-2 rounded" data-check-output><?= esc($result['output']) ?></pre>
    <?php $body = (string) ob_get_clean() ?>
    <div data-check-result="<?= esc($result['check'], 'attr') ?>" data-check-status="<?= (int) $result['status'] ?>">
        <?= theme_component('card', ['title' => lang('Devtools.checks.names.' . $result['check']), 'icon' => 'bi bi-terminal', 'body' => $body]) ?>
    </div>
<?php endif ?>
<?= $this->endSection() ?>
