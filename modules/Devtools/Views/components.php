<?php
/**
 * Devtools › Component gallery (session 53): every component in light and dark mode, with the
 * PHP call of each; another installed theme with `?theme=<slug>` (this request only).
 *
 * @var string                                                                 $layout
 * @var array<string, string>                                                  $themes slug => name
 * @var string                                                                 $current
 */
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?= $this->include('Modules\Devtools\Views\_banner') ?>
<?= $this->setVar('active', 'components')->include('Modules\Devtools\Views\_nav') ?>
<p class="text-body-secondary"><?= esc(lang('Devtools.components.intro')) ?></p>
<form method="get" action="<?= esc(backend_url('devtools/components'), 'attr') ?>" class="row g-2 align-items-end mb-3">
    <div class="col-8 col-md-4">
        <label class="form-label" for="gallery-theme"><?= esc(lang('Devtools.components.theme')) ?></label>
        <select class="form-select" id="gallery-theme" name="theme">
            <option value=""><?= esc(lang('Devtools.components.active')) ?></option>
            <?php foreach ($themes as $slug => $name): ?>
                <option value="<?= esc($slug, 'attr') ?>"<?= $slug === $current ? ' selected' : '' ?>><?= esc($name) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-4 col-md-2"><button type="submit" class="btn btn-outline-primary w-100"><?= esc(lang('Devtools.mail.show')) ?></button></div>
</form>
<?php $darkItems = Modules\Devtools\Components\Gallery::items('-dark') ?>
<?php foreach (Modules\Devtools\Components\Gallery::items('-light') as $i => $item): ?>
    <?php $dark = $darkItems[$i] ?>
    <section class="card shadow-sm mb-3" data-gallery-item="<?= esc($item['id'], 'attr') ?>">
        <div class="card-header"><h2 class="h6 mb-0 font-monospace"><?= esc($item['title']) ?></h2></div>
        <div class="card-body">
            <pre tabindex="0" class="small bg-body-tertiary p-2 rounded text-break"><code><?= esc($item['code']) ?></code></pre>
            <div class="row g-3">
                <div class="col-12 col-xl-6">
                    <div class="border rounded p-3 bg-body text-body" data-bs-theme="light" data-gallery-mode="light"><?= $item['html'] ?></div>
                </div>
                <div class="col-12 col-xl-6">
                    <div class="border rounded p-3 bg-body text-body" data-bs-theme="dark" data-gallery-mode="dark"><?= $dark['html'] ?></div>
                </div>
            </div>
        </div>
    </section>
<?php endforeach ?>
<?= $this->endSection() ?>
