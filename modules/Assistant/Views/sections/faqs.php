<?php
/**
 * Section `assistant.faqs` (D-51-03): the FAQs the reader may read. Override:
 * `themes/<slug>/Views/overrides/Assistant/sections/faqs.php`.
 *
 * @var array<string, mixed>                                                                                    $values heading, intro
 * @var list<array{id: int, question: string, answer: string, link: array{href: string, label: string}|null}> $items
 */
$heading = (string) $values['heading'];
?>
<div class="kp-assistant-faqs">
    <?= theme_view('Modules\Core\Views\sections\heading', ['heading' => $heading, 'intro' => (string) $values['intro'], 'centered' => false]) ?>
    <?php if ($items === []): ?>
        <p class="text-body-secondary"><?= esc(lang('Assistant.page.empty')) ?></p>
    <?php else: ?>
        <?= view('Modules\Assistant\Views\partials\list', ['items' => $items, 'tag' => $heading !== '' ? 'h3' : 'h2']) ?>
    <?php endif ?>
</div>
