<?php
/**
 * The list of FAQs shared by the page `faq` and the section `assistant.faqs`: each FAQ with an
 * anchor `#faq-<id>` (the site search links there), its answer (sanitised on the server) and link.
 *
 * @var list<array{id: int, question: string, answer: string, link: array{href: string, label: string}|null}> $items
 * @var string                                                                                                   $tag the questions' heading tag (h2 | h3)
 */
$tag = in_array($tag ?? 'h2', ['h2', 'h3'], true) ? $tag : 'h2';
?>
<div class="assistant-faq-list">
    <?php foreach ($items as $item): ?>
        <section class="assistant-faq mb-4" id="faq-<?= (int) $item['id'] ?>" aria-labelledby="faq-<?= (int) $item['id'] ?>-q">
            <<?= $tag ?> class="h5" id="faq-<?= (int) $item['id'] ?>-q"><?= esc($item['question']) ?></<?= $tag ?>>
            <?php if ($item['answer'] !== ''): ?>
                <div class="assistant-faq-answer"><?= $item['answer'] ?></div>
            <?php endif ?>
            <?php if ($item['link'] !== null): ?>
                <p class="mb-0"><a href="<?= esc($item['link']['href'], 'attr') ?>"><?= esc($item['link']['label']) ?></a></p>
            <?php endif ?>
        </section>
    <?php endforeach ?>
</div>
