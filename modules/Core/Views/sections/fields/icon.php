<?php
/**
 * An icon of Bootstrap Icons: a text input (`bi-star`) that the Core's icon picker
 * (`data-icon-picker="name"`, `core/forms/icon-picker`) completes with a preview and a
 * "Choose an icon" button. Without JavaScript the name is typed.
 *
 * @var \Modules\Core\Sections\Fields\Field $field
 * @var string                              $name
 * @var string                              $id
 * @var string                              $path
 * @var mixed                               $value
 * @var \Modules\Core\Sections\SectionFormView $view
 */
$current = is_string($value) ? $value : '';
$invalid = $view->errorFor($path) !== null;
?>
<div class="kp-icon-field">
    <label class="form-label" for="<?= esc($id, 'attr') ?>"><?= esc($field->getLabel()) ?><?= $field->isRequired() ? ' <span class="text-danger" aria-hidden="true">*</span>' : '' ?></label>
    <input type="text" class="form-control<?= $invalid ? ' is-invalid' : '' ?>" id="<?= esc($id, 'attr') ?>" name="<?= esc($name, 'attr') ?>" value="<?= esc($current, 'attr') ?>" maxlength="60" pattern="bi-[a-z0-9\-]+" placeholder="bi-star" autocomplete="off" spellcheck="false" data-icon-picker="name" aria-describedby="<?= esc($id, 'attr') ?>-icon-help">
    <div class="form-text" id="<?= esc($id, 'attr') ?>-icon-help"><?= esc(lang('Sections.icon.help')) ?></div>
</div>
