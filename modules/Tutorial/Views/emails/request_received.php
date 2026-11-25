<?php
/**
 * Confirmation email of the public "request a to-do task" form (tutorial 12), rendered in the
 * visitor's language.
 *
 * @var string $subject
 * @var string $name
 * @var string $title
 * @var string $site
 * @var string $locale
 */
?>
<!doctype html>
<html lang="<?= esc($locale) ?>">
<head><meta charset="utf-8"><title><?= esc($subject) ?></title></head>
<body>
<p><?= esc(lang('Tutorial.email.greeting', [$name])) ?></p>
<p><?= esc(lang('Tutorial.email.body', [$site])) ?></p>
<p><strong><?= esc($title) ?></strong></p>
<p><small><?= esc(lang('Tutorial.email.footer')) ?></small></p>
</body>
</html>
