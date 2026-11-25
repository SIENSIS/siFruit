<?php

declare(strict_types=1);

namespace Modules\Assistant\Tests;

use Modules\Core\Cache\CoreCache;
use Modules\Core\Search\SearchDocument;

/**
 * How the assistant answers (D-51-01): an FAQ found by a paraphrase or its keywords, per
 * language; search results when no FAQ is close enough; the contact form offered last;
 * visibility for guests, signed-in users and group members, through FAQs and search results.
 *
 * @internal
 */
final class AnswerTest extends AssistantTestCase
{
    public function testAnFaqIsFoundByAParaphraseAndByItsKeywordsInEachLanguage(): void
    {
        $id = $this->faq([
            'ca' => ['Quin és l\'horari d\'atenció?', '<p>De <strong>9 a 14 h</strong>.</p>', "a quina hora obriu\ntancat"],
            'es' => ['¿Cuál es el horario de atención?', '<p>De 9 a 14 h.</p>', "a qué hora abrís\ncerrado"],
        ], link: '/contact');

        $answer = $this->answer('Horari d\'atenció, si us plau');
        $this->assertSame('faq', $answer['type']);
        $this->assertSame($id, $answer['faq']['id']);
        $this->assertSame('<p>De <strong>9 a 14 h</strong>.</p>', $answer['faq']['answer']);
        $this->assertSame(site_url('contact'), $answer['faq']['link']['href']);

        $this->assertSame('faq', $this->answer('A quina hora obriu?')['type'], 'Found by one of its other phrasings.');
        $this->assertSame('faq', $this->answer('TANCAT')['type'], 'Found by a keyword, whatever the capitals.');

        $answer = $this->answer('¿A qué hora abrís?', 'es/assistant/ask');
        $this->assertSame('faq', $answer['type']);
        $this->assertSame('¿Cuál es el horario de atención?', $answer['faq']['question'], 'In the language of the URL.');
    }

    public function testAWordOnlyInTheAnswerIsNotEnoughThenSearchResultsThenTheContactForm(): void
    {
        $this->faq(['ca' => ['Quin és l\'horari d\'atenció?', '<p>També atenem per telèfon.</p>']]);
        service('search')->index(new SearchDocument('pages.pages', 'page-1', 'ca', 'Atenció telefònica', 'atencio', summary: 'Telèfon de la secretaria.'));

        $answer = $this->answer('telèfon');
        $this->assertSame('results', $answer['type'], 'A word only in an FAQ\'s answer does not pick that FAQ.');
        $this->assertSame('Atenció telefònica', $answer['results'][0]['title']);
        $this->assertSame(service('localeurls')->url('atencio', 'ca'), $answer['results'][0]['url']);
        $this->assertSame(site_url('contact'), $answer['contact'], 'The contact form is offered last.');

        $answer = $this->answer('piscina municipal');
        $this->assertSame('none', $answer['type']);
        $this->assertSame(site_url('contact'), $answer['contact']);

        setting('Assistant.offerContact', false);
        $this->assertNull($this->answer('piscina municipal')['contact']);

        setting('Assistant.offerContact', true);
        db_connect()->table('modules')->where('name', 'Contact')->update(['enabled' => 0]);
        (new CoreCache())->invalidate('modules');
        $this->assertNull($this->answer('piscina municipal')['contact'], 'No contact page, no link.');
    }

    public function testTheSourcesSettingAndTheNumberOfResults(): void
    {
        foreach (range(1, 5) as $i) {
            service('search')->index(new SearchDocument('pages.pages', 'page-' . $i, 'ca', 'Excursió ' . $i, 'excursio-' . $i));
        }

        service('search')->index(new SearchDocument('news.posts', 'post-1', 'ca', 'Excursió a la neu', 'news/neu'));

        $this->assertCount(3, $this->answer('excursió')['results'], 'Assistant.results (3).');

        setting('Assistant.results', 10);
        setting('Assistant.sources', ['news.posts']);
        $results = $this->answer('excursió')['results'];
        $this->assertCount(1, $results);
        $this->assertSame('Excursió a la neu', $results[0]['title']);
    }

    public function testVisibilityThroughFaqsAndSearchResults(): void
    {
        $this->faq(['ca' => ['On són els documents interns?', 'Al back-office.']], 'private');
        $this->faq(['ca' => ['On són les actes del claustre?', 'A la carpeta del claustre.']], 'groups', ['editor']);
        $this->faq(['ca' => ['On són els documents antics?', 'Arxivats.']], active: false);
        service('search')->index(new SearchDocument('pages.pages', 'page-1', 'ca', 'Intranet: documents interns', 'intranet', visibility: 'private'));

        $answer = $this->answer('documents interns');
        $this->assertNotSame('faq', $answer['type'], 'A guest never gets a private FAQ.');
        $this->assertStringNotContainsString('Intranet', (string) json_encode($answer), 'Nor a private page.');
        $this->assertSame('none', $this->answer('actes del claustre')['type']);
        $this->assertSame('none', $this->answer('documents antics')['type'], 'Inactive FAQs are never used.');

        $this->createUser('ana@example.com', 'user');
        $this->login('ana@example.com');
        $this->assertSame('faq', $this->answer('documents interns')['type'], 'Signed-in users get it.');
        $this->assertSame('none', $this->answer('actes del claustre')['type'], 'Not a group they are not in.');

        $this->createUser('eva@example.com', 'editor');
        $this->login('eva@example.com');
        $this->assertSame('faq', $this->answer('actes del claustre')['type'], 'Members of the group get it.');
    }

    public function testEmptyAndCommonWordsAskForMore(): void
    {
        $this->assertSame('empty', $this->answer('i el de')['type']);
    }
}
