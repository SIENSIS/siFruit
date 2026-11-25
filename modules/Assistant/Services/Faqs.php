<?php

declare(strict_types=1);

namespace Modules\Assistant\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Entities\User;
use Modules\Core\Content\Audience;
use Modules\Core\Content\ContentTranslations;
use Modules\Core\Sections\SectionLinks;
use Modules\Core\Security\HtmlSanitizer;

/**
 * The frequently asked questions (D-51-01; docs/assistant.md): who may read one (an active FAQ of
 * its audience, the Core's `Audience`: `public`, `private` = signed-in users, `groups` = members of
 * its groups), its texts in a language (`ContentTranslations::pick()`, the site's fallback) and
 * its link (an internal path or an `https://` address, checked like menu items).
 *
 * No raw SQL: every value is bound or escaped by the Query Builder.
 */
final class Faqs
{
    public const TEXTS = ['question', 'answer', 'keywords', 'link_label'];

    /**
     * Adds "active and readable by this visitor" to a query on `assistant_faqs`.
     *
     * @param BaseBuilder<BaseConnection<object|resource, object|resource>> $builder
     *
     * @return BaseBuilder<BaseConnection<object|resource, object|resource>>
     */
    public static function visible(BaseBuilder $builder, ?User $user): BaseBuilder
    {
        $builder->where('assistant_faqs.active', 1);

        return Audience::apply($builder, $user, 'assistant_faqs', 'assistant_faq_groups', 'faq_id');
    }

    /**
     * One FAQ the visitor may read, in a language, or null.
     *
     * @return array{id: int, question: string, answer: string, link: array{href: string, label: string}|null}|null
     */
    public function find(int $id, ?User $user, string $locale): ?array
    {
        $row = self::visible(db_connect()->table('assistant_faqs'), $user)->where('assistant_faqs.id', $id)->get(1)->getRowArray();

        return is_array($row) ? $this->present($row, $locale) : null;
    }

    /**
     * Every FAQ the visitor may read, in their order, in a language (the page `faq`, the section
     * `assistant.faqs`).
     *
     * @return list<array{id: int, question: string, answer: string, link: array{href: string, label: string}|null}>
     */
    public function listFor(?User $user, string $locale): array
    {
        $rows  = self::visible(db_connect()->table('assistant_faqs')->select('assistant_faqs.*'), $user)
            ->orderBy('assistant_faqs.position')->orderBy('assistant_faqs.id')->get()->getResultArray();
        $items = [];

        foreach ($rows as $row) {
            $item = $this->present($row, $locale);

            if ($item['question'] !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The first FAQs the visitor may read (their order), for the bubble's suggested questions.
     *
     * @return list<array{id: int, question: string}>
     */
    public function suggestions(?User $user, string $locale, int $limit = 4): array
    {
        $rows = self::visible(db_connect()->table('assistant_faqs')->select('assistant_faqs.*'), $user)
            ->orderBy('assistant_faqs.position')->orderBy('assistant_faqs.id')->limit($limit)->get()->getResultArray();
        $texts = $this->texts(array_map(static fn (array $row): int => (int) $row['id'], $rows), $locale);
        $list  = [];

        foreach ($rows as $row) {
            $question = $texts[(int) $row['id']]['question'] ?? '';

            if ($question !== '') {
                $list[] = ['id' => (int) $row['id'], 'question' => $question];
            }
        }

        return $list;
    }

    /**
     * The texts of an FAQ row in a language and its resolved link.
     *
     * @param array<string, mixed> $row an `assistant_faqs` row
     *
     * @return array{id: int, question: string, answer: string, link: array{href: string, label: string}|null}
     */
    public function present(array $row, string $locale): array
    {
        $id    = (int) $row['id'];
        $texts = $this->texts([$id], $locale)[$id] ?? array_fill_keys(self::TEXTS, '');
        $link  = self::link(is_string($row['link'] ?? null) ? $row['link'] : null);

        return [
            'id'       => $id,
            'question' => $texts['question'],
            'answer'   => HtmlSanitizer::clean($texts['answer']),
            'link'     => $link === null ? null : ['href' => $link, 'label' => $texts['link_label'] !== '' ? $texts['link_label'] : lang('Assistant.bubble.moreInfo')],
        ];
    }

    /**
     * The address of a stored link: an internal path (`/qui-som`, localised like every public link)
     * or an `https://` address; null when empty or no longer valid.
     */
    public static function link(?string $link): ?string
    {
        $link = $link === null ? null : SectionLinks::checkUrl($link);

        if ($link === null) {
            return null;
        }

        return SectionLinks::isInternal($link) ? site_url(ltrim($link, '/')) : $link;
    }

    /**
     * Why a link cannot be stored, or null (empty is allowed).
     */
    public static function linkError(string $link): ?string
    {
        return trim($link) === '' || SectionLinks::checkUrl($link) !== null ? null : lang('Assistant.errors.link');
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array<string, string>> id => field => text
     */
    public function texts(array $ids, string $locale): array
    {
        return ContentTranslations::texts('assistant_faq_translations', 'faq_id', $ids, self::TEXTS, $locale);
    }
}
