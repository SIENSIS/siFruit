<?php

declare(strict_types=1);

namespace Modules\Tutorial\Tests;

use Config\Services;
use Modules\Core\Theme\ThemeRegistry;

/**
 * The child theme of tutorial 9, `themes/tutorial-demo` (child of `default`): it overrides the Tutorial module's
 * public page, changes the primary colour and the logo, and keeps the parent's layout and CSS;
 * no inline code (strict CSP).
 *
 * @internal
 */
final class ChildThemeTest extends TutorialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ThemeRegistry::reset();
        setting()->set('Theme.public', 'tutorial-demo');
        Services::resetSingle('theme');
    }

    protected function tearDown(): void
    {
        setting()->forget('Theme.public');
        Services::resetSingle('theme');
        ThemeRegistry::reset();
        parent::tearDown();
    }

    public function testTheThemeIsAValidChildOfTheDefaultTheme(): void
    {
        $this->assertFileExists(ROOTPATH . 'themes/tutorial-demo/Theme.php');
        $this->assertFileExists(ROOTPATH . 'themes/tutorial-demo/Views/overrides/Tutorial/public/index.php');
        $this->assertFileDoesNotExist(ROOTPATH . 'themes/tutorial-demo/Views/layouts/public.php', 'Layouts come from the parent.');

        $registry = new ThemeRegistry();
        $this->assertSame('default', $registry->get('tutorial-demo')->parent);
        $this->assertSame(['tutorial-demo', 'default'], $registry->chain('tutorial-demo'));
    }

    public function testItOverridesTheTutorialPublicPageAndItsColour(): void
    {
        $this->task('Pública', ['visibility' => 'public']);

        $response = $this->send('GET', 'tutorial');
        $response->assertOK();
        $html = (string) $response->response()->getBody();

        $this->assertStringContainsString('tutorial-demo-board', $html, 'The theme\'s view of the Tutorial page.');
        $this->assertStringContainsString('Pública', $html);
        $this->assertStringContainsString('--bs-primary: #6f42c1;', $html);
        $this->assertStringContainsString('themes/tutorial-demo/img/logo.svg', $html);
        $this->assertStringContainsString('themes/tutorial-demo/css/theme.css', $html);
        $this->assertStringContainsString('themes/default/css/theme.css', $html);
        $this->assertStringContainsString('class="layout-public', $html);

        // A Tutorial page it does not override keeps the module's view.
        $this->assertStringNotContainsString('tutorial-demo-board', $this->body('GET', 'tutorial/request'));

        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bnonce=)(?![^>]*\bsrc=)(?![^>]*type="application\/json")[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $html);
        $this->assertDoesNotMatchRegularExpression('/\sstyle="/', $html);
    }
}
