<?php

declare(strict_types=1);

namespace Modules\Tutorial\Tests;

use Modules\Core\Permissions\CrudPermissions;
use Modules\Core\Tests\ContentLocalesTrait;
use Modules\Dashboard\Tests\DashboardTestCase;

/**
 * Base of the Tutorial module's feature tests: the Auth fixture, every manifest's declarations (the Tutorial
 * widgets included), the permission catalogue and default grants, and the fixtures of
 * `TutorialFixtures`. The module is enabled like every other one in the tests.
 */
abstract class TutorialTestCase extends DashboardTestCase
{
    use ContentLocalesTrait;
    use TutorialFixtures;

    protected array $widgetModules = ['Core', 'Dashboard', 'Users', 'Tutorial'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useShippedContentLocales();
        CrudPermissions::reset();
    }

    /**
     * The page as text with its HTML entities decoded.
     *
     * @param array<string, mixed> $params
     */
    protected function body(string $method, string $path, array $params = []): string
    {
        return html_entity_decode((string) $this->send($method, $path, $params)->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
