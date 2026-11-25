<?php
/**
 * The public FAQ page (`faq`, D-51-03): the FAQs the visitor may read, each with an anchor
 * `#faq-<id>`. When a page is chosen in `Assistant.faqPage`, `faq` redirects there instead.
 *
 * @var string                                                                                                   $layout
 * @var string                                                                                                   $title
 * @var list<array{id: int, question: string, answer: string, link: array{href: string, label: string}|null}> $items
 */
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<h1 class="h2 mb-4"><?= esc($title) ?></h1>
<?php if ($items === []): ?>
    <?= theme_component('empty_state', ['icon' => 'bi bi-chat-dots', 'title' => lang('Assistant.page.empty'), 'message' => null, 'action' => null, 'compact' => false]) ?>
<?php else: ?>
    <?= view('Modules\Assistant\Views\partials\list', ['items' => $items, 'tag' => 'h2']) ?>
<?php endif ?>
<?= $this->endSection() ?>
