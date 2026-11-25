<?php
/**
 * Devtools › E-mail and notification previews (session 53): sample data, every channel, in a
 * language; the e-mail HTML in a sandboxed iframe (`srcdoc`). Nothing is sent or stored.
 *
 * @var string                                                                                                                                 $layout
 * @var array<string, array{kind: string, title: string, source: string}>                                                                       $items
 * @var string                                                                                                                                 $id
 * @var string                                                                                                                                 $locale
 * @var list<string>                                                                                                                           $locales
 * @var array{subject: string, html: string, text: string, database: array<string, mixed>|null, push: array<string, string>|null}|null        $preview
 */
$json = static fn (mixed $v): string => (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?= $this->include('Modules\Devtools\Views\_banner') ?>
<?= $this->setVar('active', 'mail')->include('Modules\Devtools\Views\_nav') ?>
<p class="text-body-secondary"><?= esc(lang('Devtools.mail.intro')) ?></p>
<form method="get" action="<?= esc(backend_url('devtools/mail'), 'attr') ?>" class="row g-2 align-items-end mb-3">
    <div class="col-12 col-md-7">
        <label class="form-label" for="mail-item"><?= esc(lang('Devtools.mail.item')) ?></label>
        <select class="form-select" id="mail-item" name="item">
            <?php foreach (['notification', 'security'] as $kind): ?>
                <optgroup label="<?= esc(lang('Devtools.mail.kinds.' . $kind), 'attr') ?>">
                    <?php foreach ($items as $key => $item): ?>
                        <?php if ($item['kind'] === $kind): ?>
                            <option value="<?= esc($key, 'attr') ?>"<?= $key === $id ? ' selected' : '' ?>><?= esc($item['title']) ?></option>
                        <?php endif ?>
                    <?php endforeach ?>
                </optgroup>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label" for="mail-locale"><?= esc(lang('Devtools.mail.locale')) ?></label>
        <select class="form-select" id="mail-locale" name="locale">
            <?php foreach ($locales as $option): ?>
                <option value="<?= esc($option, 'attr') ?>"<?= $option === $locale ? ' selected' : '' ?>><?= esc($option) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="col-6 col-md-2"><button type="submit" class="btn btn-outline-primary w-100"><?= esc(lang('Devtools.mail.show')) ?></button></div>
</form>
<?php if ($preview === null): ?>
    <?= theme_component('empty_state', ['icon' => 'bi bi-envelope', 'title' => lang('Devtools.mail.none'), 'message' => null, 'action' => null, 'compact' => true]) ?>
<?php else: ?>
    <div class="row g-3" data-mail-preview="<?= esc($id, 'attr') ?>" data-mail-locale="<?= esc($locale, 'attr') ?>">
        <div class="col-12">
            <p class="mb-1"><strong><?= esc(lang('Devtools.mail.subject')) ?>:</strong> <span data-mail-subject><?= esc($preview['subject']) ?></span> · <code><?= esc($items[$id]['source']) ?></code></p>
            <p class="small text-body-secondary"><?= esc(lang('Devtools.mail.nothingSent')) ?></p>
        </div>
        <div class="col-12 col-xl-7">
            <?= theme_component('card', ['title' => lang('Devtools.mail.channels.html'), 'icon' => 'bi bi-envelope', 'body' => '<div class="ratio ratio-4x3"><iframe class="border rounded bg-white" sandbox="" referrerpolicy="no-referrer" title="' . esc(lang('Devtools.mail.channels.html'), 'attr') . '" srcdoc="' . esc($preview['html'], 'attr') . '"></iframe></div>']) ?>
        </div>
        <div class="col-12 col-xl-5">
            <?= theme_component('card', ['title' => lang('Devtools.mail.channels.text'), 'icon' => 'bi bi-file-text', 'body' => '<pre tabindex="0" class="small mb-0 text-break" data-mail-text>' . esc($preview['text']) . '</pre>']) ?>
        </div>
        <?php if ($preview['database'] !== null): ?>
            <div class="col-12 col-xl-6">
                <?= theme_component('card', ['title' => lang('Devtools.mail.channels.database'), 'icon' => 'bi bi-bell', 'body' => '<pre tabindex="0" class="small mb-0 text-break">' . esc($json($preview['database'])) . '</pre>']) ?>
            </div>
        <?php endif ?>
        <?php if ($preview['push'] !== null): ?>
            <div class="col-12 col-xl-6">
                <?= theme_component('card', ['title' => lang('Devtools.mail.channels.push'), 'icon' => 'bi bi-phone-vibrate', 'body' => '<pre tabindex="0" class="small mb-0 text-break">' . esc($json($preview['push'])) . '</pre>']) ?>
            </div>
        <?php endif ?>
    </div>
<?php endif ?>
<?= $this->endSection() ?>
