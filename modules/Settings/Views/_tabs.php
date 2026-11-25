<?php
/**
 * The settings tabs (links: each tab is its own page), underlined links that wrap onto more lines
 * when they do not fit (no horizontal scroll).
 *
 * @var list<array{id: string, label: string, url: string, icon: string, active: bool}> $tabs
 */
?>
<nav aria-label="<?= esc(lang('Settings.title'), 'attr') ?>" class="mb-3 pb-2 border-bottom">
    <ul class="nav nav-underline flex-wrap column-gap-3 row-gap-1 settings-tabs">
        <?php foreach ($tabs as $tab): ?>
            <li class="nav-item">
                <a class="nav-link text-nowrap<?= $tab['active'] ? ' active' : '' ?>" href="<?= esc($tab['url'], 'attr') ?>"<?= $tab['active'] ? ' aria-current="page"' : '' ?>>
                    <i class="<?= esc($tab['icon'], 'attr') ?> me-1" aria-hidden="true"></i><?= esc($tab['label']) ?>
                </a>
            </li>
        <?php endforeach ?>
    </ul>
</nav>
