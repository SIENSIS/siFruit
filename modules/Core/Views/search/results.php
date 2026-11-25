<?php
/**
 * The public results page of the site search (D-50-01, docs/search.md): the search form, the
 * number of results, each result with its source, date, title and excerpt (the query's words
 * highlighted, escaped), pagination, and "no results" with the contact link when there is one.
 *
 * @var string                                     $layout
 * @var string                                     $title
 * @var string                                     $query
 * @var Modules\Core\Search\SearchResults|null     $results
 * @var string                                     $locale
 * @var string                                     $pager
 * @var array<string, string>                      $labels  source id => label
 * @var string|null                                $contactUrl
 */

use Modules\Core\Search\Highlighter;

?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<div class="site-search-page">
    <h1 class="h2 mb-3 text-break"><?= esc($title) ?></h1>
    <form class="site-search-form mb-4" action="<?= esc(site_url('search'), 'attr') ?>" method="get" role="search">
        <div class="input-group">
            <label class="visually-hidden" for="site-search-page-q"><?= esc(lang('Core.search.label')) ?></label>
            <input class="form-control" type="search" id="site-search-page-q" name="q" value="<?= esc($query, 'attr') ?>" maxlength="200" placeholder="<?= esc(lang('Core.search.placeholder'), 'attr') ?>" autocomplete="off">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> <?= esc(lang('Core.search.submit')) ?></button>
        </div>
    </form>

    <?php if ($results === null): ?>
        <p class="text-body-secondary"><?= esc(lang('Core.search.intro')) ?></p>
    <?php elseif ($results->terms === []): ?>
        <p class="text-body-secondary"><?= esc(lang('Core.search.tooShort')) ?></p>
    <?php elseif ($results->isEmpty()): ?>
        <div class="site-search-empty">
            <p class="lead mb-1"><?= esc(lang('Core.search.none', [$query])) ?></p>
            <p class="text-body-secondary"><?= esc(lang('Core.search.noneTips')) ?></p>
            <?php if ($contactUrl !== null): ?>
                <p><a class="btn btn-outline-primary" href="<?= esc($contactUrl, 'attr') ?>"><i class="bi bi-envelope" aria-hidden="true"></i> <?= esc(lang('Core.search.contact')) ?></a></p>
            <?php endif ?>
        </div>
    <?php else: ?>
        <p class="text-body-secondary small mb-3" role="status">
            <?= esc(lang('Core.search.count', [$results->total])) ?>
            <?php if ($results->pages() > 1): ?> · <?= esc(lang('Core.search.pageOf', [$results->page, $results->pages()])) ?><?php endif ?>
        </p>
        <ol class="list-unstyled site-search-results">
            <?php foreach ($results->hits as $hit): ?>
                <li class="site-search-result mb-4">
                    <div class="small text-body-secondary mb-1">
                        <span class="site-search-source"><?= esc($labels[$hit->source] ?? $hit->source) ?></span>
                        <?php if ($hit->date !== null): ?> · <?= ui_date($hit->date, 'date') ?><?php endif ?>
                    </div>
                    <h2 class="h5 mb-1"><a class="text-break" href="<?= esc($hit->link(), 'attr') ?>"><?= Highlighter::highlight($hit->title, $results->terms, $locale) ?></a></h2>
                    <?php if ($hit->excerpt !== ''): ?>
                        <p class="mb-0 text-body-secondary text-break"><?= Highlighter::highlight($hit->excerpt, $results->terms, $locale) ?></p>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ol>
        <?php if ($pager !== ''): ?>
            <div class="mt-4"><?= $pager ?></div>
        <?php endif ?>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
