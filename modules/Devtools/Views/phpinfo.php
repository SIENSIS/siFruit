<?php
/**
 * Devtools › PHP info (session 53): `phpinfo()` rebuilt as escaped tables, with a search box
 * (`devtools/filter`). Development only.
 *
 * @var string                                                                                $layout
 * @var list<array{title: string, rows: list<array{cells: list<string>, header: bool}>}>      $sections
 */
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?= $this->include('Modules\Devtools\Views\_banner') ?>
<?= $this->setVar('active', 'phpinfo')->include('Modules\Devtools\Views\_nav') ?>
<p class="text-body-secondary"><?= esc(lang('Devtools.phpinfo.intro')) ?></p>
<div class="mb-3">
    <label class="form-label" for="phpinfo-search"><?= esc(lang('Devtools.phpinfo.search')) ?></label>
    <input type="search" class="form-control" id="phpinfo-search" data-component="devtools/filter" data-filter-target="[data-phpinfo-row]" data-filter-group="[data-phpinfo-section]" autocomplete="off">
</div>
<?php foreach ($sections as $i => $section): ?>
    <section class="card shadow-sm mb-3" data-phpinfo-section>
        <div class="card-header"><h2 class="h6 mb-0"><?= esc($section['title']) ?></h2></div>
        <div class="table-responsive">
            <table class="table table-sm table-striped small mb-0">
                <tbody>
                    <?php foreach ($section['rows'] as $row): ?>
                        <tr data-phpinfo-row>
                            <?php foreach ($row['cells'] as $j => $cell): ?>
                                <?php if ($row['header'] || $j === 0): ?>
                                    <th scope="<?= $row['header'] ? 'col' : 'row' ?>" class="text-break"><?= esc($cell) ?></th>
                                <?php else: ?>
                                    <td class="text-break font-monospace"><?= esc($cell) ?></td>
                                <?php endif ?>
                            <?php endforeach ?>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach ?>
<?= $this->endSection() ?>
