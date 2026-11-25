<?php
/**
 * The local navigation of the Devtools screens (session 53): every tool, the current one marked.
 *
 * @var string $active key of the current tool (Home::tools())
 */
$active ??= '';
?>
<nav class="mb-3" aria-label="<?= esc(lang('Devtools.nav'), 'attr') ?>">
    <ul class="nav nav-pills small flex-wrap gap-1" data-devtools-nav>
        <li class="nav-item"><a class="nav-link py-1 px-2" href="<?= esc(backend_url('devtools'), 'attr') ?>"><i class="bi bi-tools me-1" aria-hidden="true"></i><?= esc(lang('Devtools.title')) ?></a></li>
        <?php foreach (Modules\Devtools\Controllers\Home::tools() as $tool): ?>
            <li class="nav-item">
                <a class="nav-link py-1 px-2<?= $tool['key'] === $active ? ' active' : '' ?>" href="<?= esc($tool['url'], 'attr') ?>"<?= $tool['key'] === $active ? ' aria-current="page"' : '' ?>><i class="<?= esc($tool['icon'], 'attr') ?> me-1" aria-hidden="true"></i><?= esc($tool['title']) ?></a>
            </li>
        <?php endforeach ?>
    </ul>
</nav>
