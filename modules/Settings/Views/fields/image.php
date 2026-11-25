<?php
/**
 * An image setting (logo, favicon, app icon…): the current image, a file input ("Browse…") whose
 * file replaces it when the form is saved (Forms\ImageUploads) and, when it differs from the
 * default, a box to go back to the default image.
 *
 * @var \Modules\Settings\Forms\Field $field
 * @var string                        $id
 * @var string                        $url     preview of the current image ('' for none)
 * @var string                        $default preview of the default image ('' for none)
 * @var string|null                   $error
 */
?>
<div class="col-md-6 settings-field settings-image">
    <label for="<?= esc($id, 'attr') ?>" class="form-label"><?= esc(lang($field->label)) ?></label>
    <div class="d-flex align-items-start gap-3">
        <?php if ($url !== ''): ?>
            <img src="<?= esc($url, 'attr') ?>" alt="<?= esc(lang('Settings.image.current'), 'attr') ?>" width="80" height="80" class="img-thumbnail object-fit-contain bg-body-tertiary flex-shrink-0" loading="lazy">
        <?php else: ?>
            <span class="img-thumbnail d-inline-flex p-3 fs-3 text-body-secondary bg-body-tertiary flex-shrink-0"><i class="bi bi-image" aria-hidden="true"></i><span class="visually-hidden"><?= esc(lang('Settings.image.none')) ?></span></span>
        <?php endif ?>
        <div class="flex-grow-1 min-w-0">
            <input type="file" class="form-control<?= $error !== null ? ' is-invalid' : '' ?>" id="<?= esc($id, 'attr') ?>" name="<?= esc($field->name . '-file', 'attr') ?>"
                accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" aria-describedby="<?= esc($id, 'attr') ?>-help"<?= $field->readOnly ? ' disabled' : '' ?>>
            <?php if ($field->isOverridden() && ! $field->readOnly): ?>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="<?= esc($id . '-reset', 'attr') ?>" name="<?= esc($field->name . '-reset', 'attr') ?>" value="1">
                    <label class="form-check-label" for="<?= esc($id . '-reset', 'attr') ?>"><?= esc(lang($default !== '' ? 'Settings.image.reset' : 'Settings.image.remove')) ?></label>
                </div>
            <?php endif ?>
        </div>
    </div>
    <?= $this->include('Modules\Settings\Views\fields\_meta') ?>
</div>
