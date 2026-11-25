<?php

declare(strict_types=1);

namespace Modules\Assistant\Services;

use Modules\Core\Sections\SectionLinks;
use Throwable;

/**
 * The page chosen to show the FAQs (D-51-03, setting `Assistant.faqPage`, a page id; like
 * `News.listPage`, D-46-03): a page of the Pages module with the section `assistant.faqs`, whose
 * address per language the editor chooses. When it is set and published, `GET faq` answers a 301
 * redirect to it in the visitor's language (the browser keeps the `#faq-<id>` of the search
 * results). Its URL comes from the link type `pages.page` of the Core's link registry, so the
 * module does not depend on Pages: with Pages disabled, or the page gone or unpublished, `faq`
 * shows the list itself.
 */
final class FaqPage
{
    /** The link type of a page (Pages module). */
    public const LINK_TYPE = 'pages.page';

    public function id(): ?int
    {
        try {
            $value = (string) setting('Assistant.faqPage');
        } catch (Throwable) {
            return null;
        }

        return ctype_digit($value) && $value !== '0' ? (int) $value : null;
    }

    /** The public address of the page, or null (not set, gone, not published, Pages disabled). */
    public function url(): ?string
    {
        $id = $this->id();

        return $id === null ? null : (new SectionLinks())->href(self::LINK_TYPE, (string) $id);
    }

    /**
     * The pages that can be chosen: id => title (tree order), or [] without the Pages module.
     *
     * @return array<int|string, string>
     */
    public function options(): array
    {
        $type = (new SectionLinks())->types()[self::LINK_TYPE] ?? null;

        try {
            return $type?->options() ?? [];
        } catch (Throwable) {
            return [];
        }
    }
}
