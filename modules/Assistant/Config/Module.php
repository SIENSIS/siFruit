<?php

declare(strict_types=1);

namespace Modules\Assistant\Config;

use Modules\Core\Config\ModuleManifest;

/**
 * Manifest of the Assistant module (session 51, D-51-01, D-51-02; docs/assistant.md): the classic
 * chat bubble of the public site, without any external service. Visitors ask a question; the
 * assistant answers from the frequently asked questions kept here and from the site search index
 * (session 50), with links, and offers the contact form when nothing fits.
 *
 * Disabled by default (`enabledByDefault`), and the bubble is off until `Assistant.enabled`.
 *
 * Resources (permissions `assistant-<resource>.<operation>`):
 * - `faqs` (kpcrudy): list, add, edit, delete, export. Holders of `assistant-faqs.add` also see
 *   the unanswered questions (when they are kept, D-51-02) and turn them into FAQs.
 */
final class Module extends ModuleManifest
{
    public string $name        = 'Assistant';
    public string $version     = '0.1.0';
    public string $icon        = 'bi bi-chat-dots';
    public string $description = 'Assistant.module.description';
    public string $author      = 'kpskeletor';
    public array $requires     = ['Core' => '^1.0'];
    public bool $enabledByDefault = false;

    public array $resources = [
        'faqs' => [
            'label'  => 'Assistant.res.faqs',
            'type'   => 'crud',
            'except' => ['show', 'import', 'trash', 'restore', 'purge'],
        ],
    ];

    public array $defaultGrants = [
        'admin'  => ['assistant-faqs.*'],
        'editor' => ['assistant-faqs.list', 'assistant-faqs.add', 'assistant-faqs.edit'],
    ];

    public array $menu = [
        ['resource' => 'faqs', 'label' => 'Assistant.menu.faqs', 'icon' => 'bi bi-chat-dots', 'order' => 46],
        ['route' => 'assistant-unanswered', 'permission' => 'assistant-faqs.add', 'label' => 'Assistant.menu.unanswered', 'icon' => 'bi bi-question-circle', 'order' => 1, 'parent' => 'faqs'],
    ];

    /**
     * Settings › Assistant (D-51-01, D-51-02). The bubble only shows with `enabled` on.
     */
    public array $settings = [
        'enabled' => [
            'type'    => 'bool',
            'default' => false,
            'label'   => 'Assistant.settings.enabled',
            'help'    => 'Assistant.settings.enabledHelp',
            'group'   => 'bubble',
        ],
        'greeting' => [
            'type'    => 'translated',
            'default' => 'Assistant.bubble.defaultGreeting',
            'rules'   => 'required|max_length[300]',
            'label'   => 'Assistant.settings.greeting',
            'help'    => 'Assistant.settings.greetingHelp',
            'group'   => 'bubble',
        ],
        'results' => [
            'type'    => 'int',
            'default' => 3,
            'rules'   => 'required|integer|greater_than_equal_to[1]|less_than_equal_to[10]',
            'label'   => 'Assistant.settings.results',
            'help'    => 'Assistant.settings.resultsHelp',
            'group'   => 'bubble',
        ],
        'sources' => [
            'type'            => 'multiselect',
            'default'         => [],
            'optionsProvider' => \Modules\Assistant\Settings\SourceOptions::class,
            'label'           => 'Assistant.settings.sources',
            'help'            => 'Assistant.settings.sourcesHelp',
            'group'           => 'bubble',
        ],
        'offerContact' => [
            'type'    => 'bool',
            'default' => true,
            'label'   => 'Assistant.settings.offerContact',
            'help'    => 'Assistant.settings.offerContactHelp',
            'group'   => 'bubble',
        ],
        // D-51-03: the page that shows the FAQs (section `assistant.faqs`); `faq` redirects there.
        'faqPage' => [
            'type'            => 'select',
            'default'         => '',
            'optionsProvider' => \Modules\Assistant\Settings\FaqPageOptions::class,
            'label'           => 'Assistant.settings.faqPage',
            'help'            => 'Assistant.settings.faqPageHelp',
            'group'           => 'page',
        ],
        // D-51-02: nothing is stored unless this is on; then only anonymised unanswered questions.
        'logUnanswered' => [
            'type'    => 'bool',
            'default' => false,
            'label'   => 'Assistant.settings.logUnanswered',
            'help'    => 'Assistant.settings.logUnansweredHelp',
            'group'   => 'privacy',
        ],
        'unansweredDays' => [
            'type'    => 'int',
            'default' => 90,
            'rules'   => 'required|integer|greater_than_equal_to[7]|less_than_equal_to[365]',
            'label'   => 'Assistant.settings.unansweredDays',
            'help'    => 'Assistant.settings.unansweredDaysHelp',
            'group'   => 'privacy',
        ],
    ];

    /** Retention of the unanswered questions (D-51-02). */
    public array $tasks = [\Modules\Assistant\Tasks\PurgeUnanswered::class];

    /** The FAQs are a source of the site search (D-51-01, docs/search.md). */
    public array $searchSources = [
        'faqs' => ['label' => 'Assistant.search.source', 'source' => \Modules\Assistant\Search\FaqSearch::class],
    ];

    /** Section type of pages built from sections (D-51-03): the list of FAQs. */
    public array $sectionTypes = [\Modules\Assistant\Sections\FaqListSection::class];

    /** Only its tables. */
    public array $backup = [];

    /** @var list<string> Files the module uses, for the unused-files scan (D-55-01, docs/storage.md) */
    public array $fileReferences = [
        \Modules\Assistant\Storage\FaqFileReferences::class,
    ];

    /** No extra Content-Security-Policy sources: the bubble is served by the site. */
    public array $csp = [];

    /** No outbound requests: no AI, no external service (D-51-01). */
    public array $outboundHosts = [];

    /** No API resources: the bubble talks to `POST assistant/ask`. */
    public array $api = [];
}
