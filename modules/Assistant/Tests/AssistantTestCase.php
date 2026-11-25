<?php

declare(strict_types=1);

namespace Modules\Assistant\Tests;

use CodeIgniter\Test\TestResponse;
use Config\App;
use Config\Services;
use Modules\Assistant\Search\FaqSearch;
use Modules\Auth\Tests\AuthTestCase;
use Modules\Core\Cache\CoreCache;
use Modules\Core\Modules\ModuleDiscovery;
use Modules\Core\Permissions\CrudPermissions;
use Modules\Core\Registry\DeclarationRegistry;
use Modules\Core\Tests\ContentLocalesTrait;

/**
 * Base of the Assistant tests: the Auth fixture (every module migrated and enabled, catalogue and
 * default grants synced, requests with session and CSRF), Catalan as the site's default language,
 * the declarations stored (search sources), an empty search index, the bubble on, and helpers that
 * write FAQs (indexed like the CRUD does) and ask the endpoint.
 */
abstract class AssistantTestCase extends AuthTestCase
{
    use ContentLocalesTrait;

    private string $defaultLocale = 'en';

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultLocale              = config(App::class)->defaultLocale;
        config(App::class)->defaultLocale = 'ca';
        $this->useShippedContentLocales();
        setting('Core.locales', ['ca', 'es', 'en']);
        CrudPermissions::reset();
        cache()->clean();
        service('theme')->reset();
        db_connect()->table('search_terms')->truncate();
        db_connect()->table('search_documents')->truncate();
        $this->storeDeclarations();
        setting('Assistant.enabled', true);
    }

    protected function tearDown(): void
    {
        foreach (['enabled', 'logUnanswered', 'offerContact', 'sources', 'results', 'unansweredDays', 'faqPage'] as $key) {
            service('settings')->forget('Assistant.' . $key);
        }

        service('settings')->forget('Core.locales');
        service('settings')->forget('Core.defaultLocale');
        db_connect()->table('modules')->update(['enabled' => 1]);
        (new CoreCache())->invalidate('modules');
        config(App::class)->defaultLocale = $this->defaultLocale;
        Services::resetSingle('settings');
        Services::resetSingle('language');
        Services::resetSingle('request');
        Services::resetSingle('localeurls');
        parent::tearDown();
    }

    /**
     * An FAQ indexed like the CRUD does; texts: locale => [question, answer, keywords].
     *
     * @param array<string, array{0: string, 1?: string, 2?: string}> $texts
     * @param list<string>                                             $groups
     */
    protected function faq(array $texts, string $visibility = 'public', array $groups = [], bool $active = true, ?string $link = null, int $position = 0): int
    {
        $db = db_connect();
        $db->table('assistant_faqs')->insert(['visibility' => $visibility, 'link' => $link, 'active' => $active ? 1 : 0, 'position' => $position, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $id = (int) $db->insertID();

        foreach ($texts as $locale => $text) {
            $db->table('assistant_faq_translations')->insert(['faq_id' => $id, 'locale' => $locale, 'question' => $text[0], 'answer' => $text[1] ?? '', 'keywords' => $text[2] ?? null, 'link_label' => null]);
        }

        foreach ($groups as $group) {
            $row = $db->table('groups')->where('name', $group)->get()->getRowArray();
            $this->assertIsArray($row, "Group {$group} exists.");
            $db->table('assistant_faq_groups')->insert(['faq_id' => $id, 'group_id' => (int) $row['id']]);
        }

        FaqSearch::sync($id);

        return $id;
    }

    /**
     * Asks the endpoint as the bubble does (JSON).
     */
    protected function ask(string $question, string $path = 'assistant/ask'): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->send('POST', $path, ['q' => $question]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function answer(string $question, string $path = 'assistant/ask'): array
    {
        $response = $this->ask($question, $path);
        $response->assertOK();
        $data = json_decode((string) $response->response()->getBody(), true);
        $this->assertIsArray($data);

        return $data;
    }

    protected function storeDeclarations(): void
    {
        $manifests = [];

        foreach ((new ModuleDiscovery())->discover() as $name => $module) {
            if ($module->isValid() && $module->manifest !== null) {
                $manifests[$name] = $module->manifest;
            }
        }

        $registry = new DeclarationRegistry();
        $registry->store($registry->build($manifests));
        (new CoreCache())->invalidate('modules');
        service('routes')->loadRoutes();
    }
}
