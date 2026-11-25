<?php
/**
 * The site search box of the public navbar (theme option `showSearch`, D-50-01, docs/search.md):
 * a GET form to the results page in the visitor's language (`site_url('search')`). Inside the
 * collapsed menu at phone width; at tablet widths (md, where the expanded navbar is narrow) an
 * icon link to the results page, which has the full form.
 */
if (! theme_option('showSearch')) {
    return;
}
?>
<a class="site-search-link nav-link d-none d-md-inline-flex d-lg-none align-items-center ms-md-2 px-2" href="<?= esc(site_url('search'), 'attr') ?>" aria-label="<?= esc(lang('Core.search.label'), 'attr') ?>" title="<?= esc(lang('Core.search.label'), 'attr') ?>"><i class="bi bi-search" aria-hidden="true"></i></a>
<form class="site-search-box d-flex d-md-none d-lg-flex ms-lg-2 my-2 my-lg-0" action="<?= esc(site_url('search'), 'attr') ?>" method="get" role="search">
    <label class="visually-hidden" for="site-search-q"><?= esc(lang('Core.search.label')) ?></label>
    <div class="input-group input-group-sm">
        <input class="form-control" type="search" id="site-search-q" name="q" maxlength="200" placeholder="<?= esc(lang('Core.search.placeholder'), 'attr') ?>" autocomplete="off">
        <button class="btn btn-outline-secondary" type="submit" aria-label="<?= esc(lang('Core.search.submit'), 'attr') ?>"><i class="bi bi-search" aria-hidden="true"></i></button>
    </div>
</form>
