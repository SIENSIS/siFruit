<?php
/**
 * The unanswered questions (D-51-02): anonymised questions, newest first, with "Create an FAQ"
 * and "Delete" (POST + CSRF, `assistant-faqs.add`).
 *
 * @var string                     $layout
 * @var list<array<string, mixed>> $rows
 * @var bool                       $logging
 * @var int                        $days
 */
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<div class="alert alert-<?= $logging ? 'info' : 'secondary' ?>" role="status">
    <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>
    <?= esc($logging ? lang('Assistant.unanswered.on', [$days]) : lang('Assistant.unanswered.off')) ?>
</div>
<?php if ($rows === []): ?>
    <?= theme_component('empty_state', ['icon' => 'bi bi-question-circle', 'title' => lang('Assistant.unanswered.empty'), 'message' => null, 'action' => null, 'compact' => false]) ?>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col"><?= esc(lang('Assistant.unanswered.question')) ?></th>
                        <th scope="col"><?= esc(lang('Assistant.unanswered.language')) ?></th>
                        <th scope="col"><?= esc(lang('Assistant.unanswered.day')) ?></th>
                        <th scope="col" class="text-end"><?= esc(lang('Assistant.unanswered.times')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= esc(lang('Assistant.unanswered.actions')) ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="text-break"><?= esc((string) $row['question']) ?></td>
                            <td><?= esc((string) $row['locale']) ?></td>
                            <td><?= ui_date((string) $row['asked_on'], 'date') ?></td>
                            <td class="text-end"><?= (int) $row['times'] ?></td>
                            <td class="text-end text-nowrap">
                                <form class="d-inline" method="post" action="<?= esc(backend_url('assistant/unanswered/faq/' . (int) $row['id']), 'attr') ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> <?= esc(lang('Assistant.unanswered.toFaq')) ?></button>
                                </form>
                                <form class="d-inline" method="post" action="<?= esc(backend_url('assistant/unanswered/delete/' . (int) $row['id']), 'attr') ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-danger" type="submit" data-confirm="<?= esc(lang('Assistant.unanswered.deleteConfirm'), 'attr') ?>"><i class="bi bi-trash" aria-hidden="true"></i> <?= esc(lang('Assistant.unanswered.delete')) ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif ?>
<?= $this->endSection() ?>
