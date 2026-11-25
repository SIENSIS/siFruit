<?php
/**
 * Authentication layout (login, register, 2FA, password reset, re-authentication): the site's
 * header (partial `site_header`, the same as the public site, session 58), a centered card and
 * the site's footer. Region: footer (below the card).
 *
 * @var string|null $title
 */
$font = preg_replace('/[^a-z0-9-]/', '', (string) theme_option('font'));
?>
<!doctype html>
<html lang="<?= esc(service('request')->getLocale()) ?>"<?= theme_mode()->attributes() ?>>
<head>
<?= $this->include(theme_resolve('partials/head')) ?>
    <meta name="robots" content="noindex">
</head>
<body class="layout-auth theme-<?= esc(theme()->slug()) ?> theme-font-<?= esc($font) ?> d-flex flex-column min-vh-100">
<a class="visually-hidden-focusable skip-link" href="#main"><?= esc(lang('Core.ui.skipToContent')) ?></a>
<?= theme_partial('site_header') ?>
<main id="main" class="site-main flex-grow-1 d-flex align-items-center py-5" tabindex="-1">
    <div class="container auth-container">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <?php if (! empty($title)): ?><h1 class="h4 mb-3 text-center"><?= esc($title) ?></h1><?php endif ?>
                <?= $this->include(theme_resolve('partials/flash')) ?>
                <?= $this->renderSection('content') ?>
            </div>
        </div>
        <div class="text-center small text-body-secondary mt-3"><?= theme_region('auth', 'footer') ?></div>
    </div>
</main>
<?= theme_partial('footer') ?>
<?= $this->include(theme_resolve('partials/cookie_notice')) ?>
<?= $this->include(theme_resolve('partials/scripts')) ?>
</body>
</html>
