<?php
/**
 * The board of to-do tasks (tutorial 3): three columns drawn on the server; each card the user may
 * move has a small form (works without JavaScript). The component `tutorial/board`
 * (`Assets/js/board.js`) adds drag and drop, sends the moves as JSON and redraws the columns from
 * `backend/tutorial/board/data`.
 *
 * @var string                                         $layout
 * @var array<string, list<array<string, mixed>>>      $columns
 * @var array<int, string>                             $projects
 * @var int|null                                       $project
 * @var bool                                           $seesAll
 * @var bool                                           $canAdd
 * @var array<string, mixed>                           $options
 */
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<div class="tutorial-board" data-component="tutorial/board" data-options="<?= esc((string) json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'attr') ?>">
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
        <form method="get" action="<?= esc(backend_url('tutorial'), 'attr') ?>" class="d-flex flex-wrap align-items-end gap-2" role="search">
            <div>
                <label class="form-label small mb-1" for="tutorial-project"><?= esc(lang('Tutorial.fields.project_id')) ?></label>
                <select class="form-select form-select-sm" id="tutorial-project" name="project">
                    <option value=""><?= esc(lang('Tutorial.board.allProjects')) ?></option>
                    <?php foreach ($projects as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= $project === $id ? ' selected' : '' ?>><?= esc($name) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-funnel" aria-hidden="true"></i> <?= esc(lang('Tutorial.board.filter')) ?></button>
        </form>
        <div class="d-flex flex-wrap gap-2">
            <span class="small text-body-secondary align-self-center"><?= esc(lang($seesAll ? 'Tutorial.board.seesAll' : 'Tutorial.board.seesMine')) ?></span>
            <?php if ($canAdd): ?>
                <a class="btn btn-sm btn-primary" href="<?= esc(backend_url('tutorial/tasks/add'), 'attr') ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> <?= esc(lang('Tutorial.board.add')) ?></a>
            <?php endif ?>
        </div>
    </div>
    <p class="visually-hidden" role="status" aria-live="polite" data-board-status></p>
    <p class="small text-body-secondary d-none" data-board-help><?= esc(lang('Tutorial.board.help')) ?></p>
    <div class="row g-3">
        <?php foreach ($columns as $status => $cards): ?>
            <div class="col-12 col-lg-4">
                <section class="card h-100 tutorial-board-column" aria-labelledby="tutorial-col-<?= esc($status, 'attr') ?>">
                    <header class="card-header d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0" id="tutorial-col-<?= esc($status, 'attr') ?>"><?= esc(lang('Tutorial.statuses.' . $status)) ?></h2>
                        <span class="badge text-bg-secondary" data-board-count><?= count($cards) ?></span>
                    </header>
                    <ul class="list-unstyled card-body d-flex flex-column gap-2 mb-0 tutorial-board-list" data-board-column="<?= esc($status, 'attr') ?>">
                        <?php foreach ($cards as $card): ?>
                            <?= theme_view('Modules\Tutorial\Views\board\card', ['card' => $card, 'status' => $status]) ?>
                        <?php endforeach ?>
                        <?php if ($cards === []): ?>
                            <li class="small text-body-secondary tutorial-board-empty" data-board-empty><?= esc(lang('Tutorial.board.emptyColumn')) ?></li>
                        <?php endif ?>
                    </ul>
                </section>
            </div>
        <?php endforeach ?>
    </div>
</div>
<?= $this->endSection() ?>
