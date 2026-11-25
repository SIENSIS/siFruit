<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Modules\Core\Theme\ZoneContent;
use RuntimeException;

/**
 * The floating elements of the public layout (Core event `themeFloating`, D-51-03): what the
 * listeners add, in order; a failing listener leaves it empty instead of breaking the page.
 *
 * @internal
 */
final class ThemeFloatingTest extends CIUnitTestCase
{
    /** @var list<callable> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = Events::listeners('themeFloating');
        Events::removeAllListeners('themeFloating');
    }

    protected function tearDown(): void
    {
        Events::removeAllListeners('themeFloating');

        foreach ($this->saved as $listener) {
            Events::on('themeFloating', $listener);
        }

        service('theme')->reset();
        parent::tearDown();
    }

    public function testListenersAddTheirHtmlInOrder(): void
    {
        $this->assertSame('', theme_floating());

        Events::on('themeFloating', static function (ZoneContent $content): void {
            $content->add('<div id="second"></div>', 20);
        });
        Events::on('themeFloating', function (ZoneContent $content): void {
            $this->assertSame('public.floating', $content->zone);
            $content->add('<div id="first"></div>', 10);
        });

        $this->assertSame("<div id=\"first\"></div>\n<div id=\"second\"></div>", theme_floating());
    }

    public function testAFailingListenerLeavesItEmpty(): void
    {
        Events::on('themeFloating', static function (): void {
            throw new RuntimeException('broken');
        });

        $this->assertSame('', theme_floating());
    }

    public function testThePublicLayoutPrintsThem(): void
    {
        $layout = (string) file_get_contents(ROOTPATH . 'themes/default/Views/layouts/public.php');

        $this->assertStringContainsString('<?= theme_floating() ?>', $layout);
    }
}
