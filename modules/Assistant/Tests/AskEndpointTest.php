<?php

declare(strict_types=1);

namespace Modules\Assistant\Tests;

use CodeIgniter\I18n\Time;
use CodeIgniter\Security\Exceptions\SecurityException;
use Modules\Assistant\Services\UnansweredLog;
use Modules\Assistant\Tasks\PurgeUnanswered;
use Modules\Core\Cache\CoreCache;
use Modules\Core\Scheduler\TaskResult;

/**
 * The endpoint `POST assistant/ask` (D-51-01, D-51-02): off with the setting or the module, CSRF,
 * rate limit, input limits, no HTML injection, no-JavaScript fallback, and nothing stored unless
 * `Assistant.logUnanswered` is on (anonymised, purged by the scheduled task).
 *
 * @internal
 */
final class AskEndpointTest extends AssistantTestCase
{
    public function testItAnswers404WhileTheBubbleIsOffOrTheModuleDisabled(): void
    {
        setting('Assistant.enabled', false);
        $this->assertNotFound('POST', 'assistant/ask', ['q' => 'horari']);

        setting('Assistant.enabled', true);
        db_connect()->table('modules')->where('name', 'Assistant')->update(['enabled' => 0]);
        (new CoreCache())->invalidate('modules');
        $this->assertNotFound('POST', 'assistant/ask', ['q' => 'horari']);
    }

    public function testThePostNeedsTheCsrfToken(): void
    {
        try {
            $this->withHeaders(['Accept' => 'application/json'])->send('POST', 'assistant/ask', ['q' => 'horari', 'csrf_test_name' => 'wrong']);
            $this->fail('A wrong CSRF token is refused.');
        } catch (SecurityException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testTheRateLimitAnswers429PerIpAndPerSession(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->ask('pregunta ' . $i)->assertOK();
        }

        $response = $this->ask('una més');
        $response->assertStatus(429);
        $this->assertNotSame('', $response->response()->getHeaderLine('Retry-After'));
        $this->assertSame('throttled', db_connect()->table('activity_log')->where('module', 'Assistant')->orderBy('id', 'desc')->get(1)->getRowArray()['action'] ?? null);

        // Another IP address with a new session starts again.
        service('superglobals')->setServer('REMOTE_ADDR', '192.0.2.77');
        $session = ['__ci_last_regenerate' => time()];
        $this->assertSame(200, $this->withHeaders(['Accept' => 'application/json'])->send('POST', 'assistant/ask', ['q' => 'nova'], $session)->response()->getStatusCode(), 'A new IP and a new session are not refused.');
    }

    public function testLongEmptyAndMaliciousInput(): void
    {
        $this->ask(str_repeat('a', 301))->assertStatus(422);
        $this->ask('')->assertStatus(422);
        $this->withHeaders(['Accept' => 'application/json'])->send('POST', 'assistant/ask', ['q' => ['a', 'b']])->assertStatus(422);

        $this->faq(['ca' => ['Com puc pagar la quota?', '<p onclick="alert(1)">Per <script>alert(1)</script>transferència.</p>', 'pagament']]);
        $answer = $this->answer('<script>alert(1)</script> pagar la quota');
        $this->assertSame('faq', $answer['type']);
        $this->assertStringNotContainsString('<script', $answer['faq']['answer']);
        $this->assertStringNotContainsString('onclick', $answer['faq']['answer']);
        $this->assertStringNotContainsString('<script>alert', (string) json_encode($answer, JSON_UNESCAPED_SLASHES), 'The question is never echoed.');

        $this->answer("' OR 1=1; DROP TABLE assistant_faqs; --");
        $this->assertTrue(db_connect()->tableExists('assistant_faqs'));
    }

    public function testWithoutJavascriptTheFormLeadsToTheSearchResults(): void
    {
        $response = $this->send('POST', 'assistant/ask', ['q' => 'horari d\'estiu']);
        $response->assertRedirectTo(site_url('search') . '?q=' . rawurlencode('horari d\'estiu'));
    }

    public function testTheAnswerIsNeverCached(): void
    {
        $response = $this->ask('horari');
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('application/json', $response->response()->getHeaderLine('Content-Type'));
    }

    public function testNothingIsStoredUnlessTheSettingAllowsItAndThenAnonymised(): void
    {
        $this->answer('Teniu piscina? Escriviu a ana@example.com o truqueu al 600 123 456');
        $this->assertSame(0, db_connect()->table('assistant_unanswered')->countAllResults(), 'Nothing stored by default.');

        setting('Assistant.logUnanswered', true);
        $this->answer('Teniu piscina? Escriviu a ana@example.com o truqueu al 600 123 456');
        $this->answer('Teniu piscina? Escriviu a ana@example.com o truqueu al 600 123 456');
        $rows = db_connect()->table('assistant_unanswered')->get()->getResultArray();

        $this->assertCount(1, $rows);
        $this->assertSame('Teniu piscina? Escriviu a [email] o truqueu al [######]', $rows[0]['question']);
        $this->assertSame('ca', $rows[0]['locale']);
        $this->assertSame(Time::today()->toDateString(), $rows[0]['asked_on']);
        $this->assertSame(2, (int) $rows[0]['times']);
        $this->assertSame(['id', 'locale', 'question', 'hash', 'asked_on', 'times'], array_keys($rows[0]), 'No IP, user, session or time.');

        $this->faq(['ca' => ['Teniu piscina?', 'No.']]);
        $this->answer('Teniu piscina?');
        $this->assertSame(1, db_connect()->table('assistant_unanswered')->countAllResults(), 'Answered questions are never stored.');
    }

    public function testAnonymisationKeepsShortNumbers(): void
    {
        $this->assertSame('Curs 2026, aula 12, codi [######]', UnansweredLog::anonymise("Curs 2026, aula 12,\n codi 12-34-56"));
        $this->assertSame('[email]', UnansweredLog::anonymise('x.y+z@mail.example.org'));
        $this->assertSame(300, mb_strlen(UnansweredLog::anonymise(str_repeat('é', 400))));
    }

    public function testThePurgeTaskDeletesOldQuestions(): void
    {
        $db = db_connect();
        $db->table('assistant_unanswered')->insertBatch([
            ['locale' => 'ca', 'question' => 'vella', 'hash' => sha1('a'), 'asked_on' => Time::today()->subDays(91)->toDateString(), 'times' => 1],
            ['locale' => 'ca', 'question' => 'recent', 'hash' => sha1('b'), 'asked_on' => Time::today()->subDays(10)->toDateString(), 'times' => 1],
        ]);

        $result = (new PurgeUnanswered())->run();

        $this->assertSame(TaskResult::SUCCESS, $result->status);
        $this->assertSame(['recent'], array_column($db->table('assistant_unanswered')->get()->getResultArray(), 'question'));

        setting('Assistant.unansweredDays', 3); // below the minimum: 7 days apply
        $this->assertSame(1, (new UnansweredLog())->purge(3));
    }
}
