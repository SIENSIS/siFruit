<?php

declare(strict_types=1);

namespace Modules\Assistant\Sections;

use Modules\Assistant\Services\Faqs;
use Modules\Core\Sections\Fields\TextareaField;
use Modules\Core\Sections\Fields\TextField;
use Modules\Core\Sections\RenderContext;
use Modules\Core\Sections\SectionData;
use Modules\Core\Sections\SectionOptions;
use Modules\Core\Sections\SectionType;

/**
 * The frequently asked questions `assistant.faqs` (D-51-03; docs/assistant.md "The FAQ page"): the
 * active FAQs the reader may read (the Core's `Audience`: everyone, signed-in users, their groups),
 * in their order and the reader's language, each with an anchor `#faq-<id>`. Put it on a page of
 * the Pages module (whose address per language the editor chooses) and choose that page in
 * `Assistant.faqPage`: the site search and `/faq` lead there.
 */
final class FaqListSection extends SectionType
{
    public function key(): string
    {
        return 'assistant.faqs';
    }

    public function label(): string
    {
        return lang('Assistant.sections.faqs.label');
    }

    public function description(): string
    {
        return lang('Assistant.sections.faqs.description');
    }

    public function icon(): string
    {
        return 'bi bi-chat-dots';
    }

    public function thumbnail(): string
    {
        helper('asset');

        return asset('assistant::img/sections/faqs.svg');
    }

    public function backgrounds(): array
    {
        return ['none', 'light'];
    }

    protected function schema(): array
    {
        return [
            TextField::make('heading')->label('Assistant.sections.fields.heading')->translated()->maxLength(120),
            TextareaField::make('intro')->label('Assistant.sections.fields.intro')->translated()->maxLength(400),
        ];
    }

    public function render(SectionData $section, RenderContext $context): string
    {
        $values = $this->resolve($section, $context->locale, $context->defaultLocale);

        return theme_view($this->view(), [
            'section' => $section,
            'values'  => $values,
            'options' => SectionOptions::normalise($section->options, $this),
            'context' => $context,
            'type'    => $this,
            'items'   => (new Faqs())->listFor($context->user, $context->locale),
        ]);
    }
}
