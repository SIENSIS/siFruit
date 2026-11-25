<?php
/**
 * The chat bubble (D-51-01; docs/assistant.md "The bubble"), printed by the public layout through
 * `theme_floating()`. Hidden until the component `assistant/bubble` mounts (no JavaScript, no
 * bubble: the search box remains). The panel is a non-modal dialog; the conversation is a list
 * announced with `aria-live="polite"`; answers are drawn by the component with DOM methods.
 *
 * @var string                                     $greeting    in the visitor's language
 * @var list<array{id: int, question: string}>     $suggestions the first FAQs the visitor may read
 * @var bool                                       $logging     unanswered questions are kept (D-51-02)
 * @var array<string, mixed>                       $options     the component's options
 */
?>
<div class="assistant" data-component="assistant/bubble" data-options="<?= esc((string) json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'attr') ?>" hidden>
    <button type="button" class="assistant-toggle btn btn-primary shadow" aria-expanded="false" aria-controls="assistant-panel" data-assistant-toggle>
        <i class="bi bi-chat-dots-fill" aria-hidden="true" data-assistant-icon></i>
        <span class="visually-hidden" data-assistant-toggle-label data-open-label="<?= esc(lang('Assistant.bubble.open'), 'attr') ?>" data-close-label="<?= esc(lang('Assistant.bubble.close'), 'attr') ?>"><?= esc(lang('Assistant.bubble.open')) ?></span>
    </button>
    <section class="assistant-panel card shadow" id="assistant-panel" role="dialog" aria-labelledby="assistant-title" hidden data-assistant-panel>
        <header class="assistant-header card-header d-flex align-items-center gap-2">
            <i class="bi bi-chat-dots" aria-hidden="true"></i>
            <h2 class="h6 mb-0 me-auto" id="assistant-title"><?= esc(lang('Assistant.bubble.title')) ?></h2>
            <button type="button" class="btn-close" aria-label="<?= esc(lang('Assistant.bubble.close'), 'attr') ?>" data-assistant-close></button>
        </header>
        <div class="assistant-body card-body" data-assistant-scroll>
            <ol class="assistant-log list-unstyled mb-0" aria-live="polite" aria-relevant="additions" data-assistant-log>
                <li class="assistant-message assistant-bot"><span class="visually-hidden"><?= esc(lang('Assistant.bubble.bot')) ?>: </span><?= esc($greeting) ?></li>
            </ol>
            <?php if ($suggestions !== []): ?>
                <div class="assistant-suggestions d-flex flex-wrap gap-2 mt-3" data-assistant-suggestions>
                    <p class="visually-hidden"><?= esc(lang('Assistant.bubble.suggestions')) ?></p>
                    <?php foreach ($suggestions as $suggestion): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary text-start" data-assistant-suggestion><?= esc($suggestion['question']) ?></button>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
        <div class="assistant-footer card-footer">
            <form class="assistant-form" action="<?= esc(site_url('assistant/ask'), 'attr') ?>" method="post" data-assistant-form>
                <?= csrf_field() ?>
                <label class="visually-hidden" for="assistant-q"><?= esc(lang('Assistant.bubble.label')) ?></label>
                <div class="input-group">
                    <input class="form-control" type="text" id="assistant-q" name="q" maxlength="300" autocomplete="off" required placeholder="<?= esc(lang('Assistant.bubble.placeholder'), 'attr') ?>" data-assistant-input>
                    <button class="btn btn-primary" type="submit" aria-label="<?= esc(lang('Assistant.bubble.send'), 'attr') ?>"><i class="bi bi-send" aria-hidden="true"></i></button>
                </div>
            </form>
            <p class="assistant-privacy small text-body-secondary mb-0 mt-2"><?= esc(lang($logging ? 'Assistant.bubble.privacyLogging' : 'Assistant.bubble.privacy')) ?></p>
        </div>
    </section>
</div>
