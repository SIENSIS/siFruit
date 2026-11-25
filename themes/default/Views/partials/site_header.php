<?php
/**
 * The header of the public site: the site logo, the `primary` menu location (partial
 * `public_menu`), the `header` region, the account link, the search box, the language switch and
 * the light/dark switch. Shared by the `public` and `auth` layouts (session 58), so the login
 * screens keep the site's header; a child theme overrides this partial to change both.
 */
?>
<header class="site-header border-bottom bg-body">
    <nav class="navbar navbar-expand-md container" aria-label="<?= esc(lang('Core.ui.mainMenu')) ?>">
        <?= theme_partial('brand', ['brandClass' => 'navbar-brand', 'brandUrl' => site_url('/')]) ?>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#site-menu" aria-controls="site-menu" aria-expanded="false" aria-label="<?= esc(lang('Core.ui.openMenu')) ?>">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="site-menu" data-menu-location="primary">
            <?= theme_partial('public_menu', ['location' => 'primary']) ?>
            <?= theme_region('public', 'header') ?>
            <?= theme_partial('account_link') ?>
            <?= theme_partial('search_box') ?>
        </div>
        <?= theme_partial('language_switch', ['switchClass' => 'site-language-switch ms-md-2']) ?>
        <?= theme_partial('theme_switch', ['switchClass' => 'site-theme-switch ms-md-1']) ?>
    </nav>
</header>
