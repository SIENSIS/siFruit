<?php

declare(strict_types=1);

namespace Modules\Assistant\Services;

use Modules\Core\Settings\TranslatedText;

/**
 * The chat bubble of the public site (D-51-01; docs/assistant.md "The bubble"): the view
 * `Views/bubble.php` (themes may override it in `overrides/Assistant/bubble.php`) with the
 * greeting in the visitor's language, the suggested questions (the first FAQs the visitor may
 * read) and the component's options (endpoint, stylesheet, texts). The component
 * (`assistant/bubble`) shows it; without JavaScript it stays hidden.
 */
final class Bubble
{
    public const SUGGESTIONS = 4;

    public function render(): string
    {
        $user   = auth()->loggedIn() ? auth()->user() : null;
        $locale = service('request')->getLocale();

        return theme_view('Modules\Assistant\Views\bubble', [
            'greeting'    => TranslatedText::get('Assistant.greeting', $locale),
            'suggestions' => (new Faqs())->suggestions($user, $locale, self::SUGGESTIONS),
            'logging'     => (bool) setting('Assistant.logUnanswered'),
            'options'     => [
                'askUrl' => site_url('assistant/ask'),
                'css'    => asset('assistant::css/bubble.css'),
                'texts'  => [
                    'you'      => lang('Assistant.bubble.you'),
                    'bot'      => lang('Assistant.bubble.bot'),
                    'thinking' => lang('Assistant.bubble.thinking'),
                    'error'    => lang('Assistant.bubble.error'),
                    'contact'  => lang('Assistant.bubble.contact'),
                    'more'     => lang('Assistant.bubble.moreInfo'),
                ],
            ],
        ]);
    }
}
