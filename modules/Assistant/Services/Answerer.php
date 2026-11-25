<?php

declare(strict_types=1);

namespace Modules\Assistant\Services;

use CodeIgniter\Shield\Entities\User;
use Modules\Assistant\Search\FaqSearch;
use Modules\Core\Frontend\ContactLink;
use Modules\Core\Search\SearchHit;
use Modules\Core\Search\SearchIndex;

/**
 * Answers a visitor's question (D-51-01; docs/assistant.md "How it answers"), always in the
 * visitor's language and with the visitor's visibility (the search index filters by audience):
 *
 * 1. the best **FAQ** (source `assistant.faqs`) when it is close enough: it matches at least 60 %
 *    of the question's terms and they weigh on average `MIN_WEIGHT` or more (found in the question
 *    or the keywords, not only in the answer) → its answer and link;
 * 2. otherwise up to `Assistant.results` **search results** of the sources the assistant may use
 *    (`Assistant.sources`; every source but the FAQs when empty) → title, excerpt, address;
 * 3. otherwise "not found".
 *
 * The contact form is offered last (results and not found) when `Assistant.offerContact` is on and
 * the site has a contact page. Unanswered questions are logged only when `Assistant.logUnanswered`
 * is on (D-51-02, `UnansweredLog`). Nothing leaves the server.
 */
final class Answerer
{
    public const MAX_LENGTH = 300;

    /** Share of the question's terms an FAQ must match. */
    public const MIN_SHARE = 0.6;

    /** Average weight per term of the question an FAQ must reach (question ×5, keywords ×3, answer ×1). */
    public const MIN_WEIGHT = 1.5;

    public function __construct(
        private readonly SearchIndex $index = new SearchIndex(),
        private readonly Faqs $faqs = new Faqs(),
    ) {
    }

    /**
     * @return array{type: string, message: string, faq?: array<string, mixed>, results?: list<array<string, string|null>>, contact: string|null}
     */
    public function answer(string $question, string $locale, ?User $user): array
    {
        $question = trim(mb_substr($question, 0, self::MAX_LENGTH, 'UTF-8'));
        $terms    = SearchIndex::queryTerms($question, $locale);

        if ($terms === []) {
            return ['type' => 'empty', 'message' => lang('Assistant.bubble.tooShort'), 'contact' => null];
        }

        $faq = $this->faq($question, $terms, $locale, $user);

        if ($faq !== null) {
            return ['type' => 'faq', 'message' => '', 'faq' => $faq, 'contact' => null];
        }

        $contact = setting('Assistant.offerContact') ? ContactLink::url() : null;
        $results = $this->results($question, $locale, $user);

        if ($results !== []) {
            return ['type' => 'results', 'message' => lang('Assistant.bubble.results'), 'results' => $results, 'contact' => $contact];
        }

        if (setting('Assistant.logUnanswered')) {
            (new UnansweredLog())->record($question, $locale);
        }

        return ['type' => 'none', 'message' => lang($contact === null ? 'Assistant.bubble.notFound' : 'Assistant.bubble.notFoundContact'), 'contact' => $contact];
    }

    /**
     * The sources the assistant answers from besides the FAQs: `Assistant.sources` among the
     * declared ones (every declared source but the FAQs when the setting is empty).
     *
     * @return list<string>
     */
    public function sources(): array
    {
        $declared = array_values(array_diff(array_keys(SearchIndex::labels()), [FaqSearch::SOURCE]));
        $chosen   = setting('Assistant.sources');
        $chosen   = is_array($chosen) ? array_values(array_filter($chosen, 'is_string')) : [];

        return $chosen === [] ? $declared : array_values(array_intersect($declared, $chosen));
    }

    /**
     * @param list<string> $terms
     *
     * @return array<string, mixed>|null
     */
    private function faq(string $question, array $terms, string $locale, ?User $user): ?array
    {
        $hit = $this->index->search($question, $locale, $user, 1, 1, [FaqSearch::SOURCE])->hits[0] ?? null;

        if ($hit === null || ! $this->closeEnough($hit, count($terms))) {
            return null;
        }

        $id = FaqSearch::idOf($hit->key);

        return $id === null ? null : $this->faqs->find($id, $user, $locale);
    }

    private function closeEnough(SearchHit $hit, int $terms): bool
    {
        return $hit->matched >= (int) ceil($terms * self::MIN_SHARE) && $hit->score >= $terms * self::MIN_WEIGHT;
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function results(string $question, string $locale, ?User $user): array
    {
        $sources = $this->sources();

        if ($sources === []) {
            return [];
        }

        $limit  = max(1, min(10, (int) setting('Assistant.results')));
        $labels = SearchIndex::labels();
        $list   = [];

        foreach ($this->index->search($question, $locale, $user, 1, $limit, $sources)->hits as $hit) {
            $list[] = [
                'title'   => $hit->title,
                'excerpt' => $hit->excerpt,
                'url'     => $hit->link(),
                'source'  => $labels[$hit->source] ?? null,
            ];
        }

        return $list;
    }
}
