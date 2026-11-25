<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Settings\Settings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Modules\Core\Frontend\Pwa;

/**
 * Minimal PWA: off by default (404), the manifest, a service worker that caches nothing, icons
 * generated with GD from the theme's app icon (else its logo) and regenerated when it changes; the
 * `favicon` and `appIcon` options in the head.
 *
 * @internal
 */
final class PwaTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $folder = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = WRITEPATH . 'tests/pwa-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->folder)) {
            rmdir($this->folder);
        }

        Services::resetSingle('settings');
        service('theme')->reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $values
     */
    private function settings(array $values): void
    {
        $settings = new class (config('Settings'), $values) extends Settings {
            /** @param array<string, mixed> $values */
            public function __construct(\Config\Settings $config, private readonly array $values)
            {
                parent::__construct($config);
            }

            public function get(string $key, ?string $context = null): mixed
            {
                if (array_key_exists($key, $this->values)) {
                    return $this->values[$key];
                }

                // Shield's settings come from its config file, like the real service without a
                // stored value (`Auth.sessionConfig` is read by `auth()` in the public layout).
                $property = str_starts_with($key, 'Auth.') ? substr($key, 5) : '';
                $auth     = config(\Config\Auth::class);

                return $property !== '' && property_exists($auth, $property) ? $auth->{$property} : null;
            }
        };
        Services::injectMock('settings', $settings);
    }

    public function testOffByDefault(): void
    {
        $this->assertFalse(Pwa::enabled());
        $this->settings([]);

        foreach (['manifest.webmanifest', 'sw.js', 'pwa/icon-192.png'] as $url) {
            try {
                $this->get($url);
                $this->fail($url . ' answered');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertStringNotContainsString('rel="manifest"', (string) $this->get('/')->response()->getBody());
    }

    public function testManifestServiceWorkerAndIcons(): void
    {
        $this->settings(['Core.pwa' => true, 'Core.pwaStart' => 'backend']);

        $manifest = $this->get('manifest.webmanifest');
        $manifest->assertStatus(200);
        $this->assertStringStartsWith('application/manifest+json', $manifest->response()->getHeaderLine('Content-Type'));
        $data = json_decode((string) $manifest->response()->getBody(), true);
        $this->assertSame('standalone', $data['display']);
        $this->assertSame(base_url('backend'), $data['start_url']);
        $this->assertSame(['192x192', '512x512'], array_column($data['icons'], 'sizes'));
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $data['theme_color']);

        $worker = $this->get('sw.js');
        $worker->assertStatus(200);
        $this->assertStringStartsWith('text/javascript', $worker->response()->getHeaderLine('Content-Type'));
        $source = (string) $worker->response()->getBody();
        $this->assertStringContainsString("addEventListener('fetch'", $source);
        $this->assertStringNotContainsString('caches.', $source, 'nothing is cached');
        $this->assertStringNotContainsString('respondWith', $source, 'every request goes to the network');

        $icon = $this->get('pwa/icon-192.png');
        $icon->assertStatus(200);
        $this->assertSame('image/png', $icon->response()->getHeaderLine('Content-Type'));
        $size = getimagesizefromstring((string) $icon->response()->getBody());
        $this->assertSame([192, 192], [$size[0] ?? 0, $size[1] ?? 0]);

        $html = (string) $this->get('/')->response()->getBody();
        $this->assertStringContainsString('<link rel="manifest" href="' . base_url('manifest.webmanifest') . '">', $html);
        $this->assertMatchesRegularExpression('/<meta name="theme-color" media="\(prefers-color-scheme: dark\)" content="#[0-9a-f]{6}">/', $html);

        foreach (['pwa/icon-100.png', 'pwa/icon-0192.png'] as $url) {
            try {
                $this->get($url);
                $this->fail($url . ' answered');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIconsAreRegeneratedWhenTheLogoChanges(): void
    {
        $pwa  = new Pwa(service('theme'), $this->folder);
        $icon = $pwa->icon(512);
        $this->assertSame([512, 512], array_slice(getimagesize($icon) ?: [], 0, 2));
        $this->assertNull($pwa->logoFile(), 'no logo: the default icon');

        $logoName = 'test-logo-' . bin2hex(random_bytes(3)) . '.png';
        $logo     = FCPATH . 'uploads/' . $logoName;
        $image    = imagecreatetruecolor(300, 100);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        imagepng($image, $logo);

        try {
            $options = new \ReflectionProperty(\Modules\Core\Theme\ThemeManager::class, 'options');
            $manager = service('theme');
            $manager->reset();
            $options->setValue($manager, ['public' => new \Modules\Core\Theme\ThemeOptions('default', $manager->manifests('public'), static fn (string $key): mixed => $key === 'logo' ? 'uploads/' . $logoName : null)]);

            $pwa = new Pwa($manager, $this->folder);
            $this->assertSame(realpath($logo), $pwa->logoFile());
            $new = $pwa->icon(512);
            $this->assertNotSame($icon, $new, 'a new logo gives a new icon');
            $this->assertFileDoesNotExist($icon, 'the old icon is removed');

            $pixel = imagecolorat(imagecreatefrompng($new), 256, 256);
            $this->assertSame([200, 30, 30], [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF], 'the logo is drawn in the centre');
        } finally {
            unlink($logo);
        }

        $unsafe = new \ReflectionProperty(\Modules\Core\Theme\ThemeManager::class, 'options');
        foreach (['../.env', 'https://example.com/logo.png', 'assets/vendor/vendor.json'] as $value) {
            $manager = service('theme');
            $manager->reset();
            $unsafe->setValue($manager, ['public' => new \Modules\Core\Theme\ThemeOptions('default', $manager->manifests('public'), static fn (string $key): mixed => $key === 'logo' ? $value : null)]);
            $this->assertNull((new Pwa($manager, $this->folder))->logoFile(), $value);
        }
    }

    public function testTheAppIconIsPreferredToTheLogo(): void
    {
        $names = [];
        foreach (['logo' => [200, 30, 30], 'appIcon' => [30, 160, 90]] as $option => [$r, $g, $b]) {
            $names[$option] = 'test-' . $option . '-' . bin2hex(random_bytes(3)) . '.png';
            $image          = imagecreatetruecolor(100, 100);
            imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, $g, $b));
            imagepng($image, FCPATH . 'uploads/' . $names[$option]);
        }

        $property = new \ReflectionProperty(\Modules\Core\Theme\ThemeManager::class, 'options');
        $use      = static function (array $values) use ($property): \Modules\Core\Theme\ThemeManager {
            $manager = service('theme');
            $manager->reset();
            $property->setValue($manager, ['public' => new \Modules\Core\Theme\ThemeOptions('default', $manager->manifests('public'), static fn (string $key): mixed => $values[$key] ?? null)]);

            return $manager;
        };

        try {
            $pwa = new Pwa($use(['logo' => 'uploads/' . $names['logo'], 'appIcon' => 'uploads/' . $names['appIcon']]), $this->folder);
            $this->assertSame(realpath(FCPATH . 'uploads/' . $names['appIcon']), $pwa->logoFile());
            $pixel = imagecolorat(imagecreatefrompng($pwa->icon(192)), 96, 96);
            $this->assertSame([30, 160, 90], [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF], 'the app icon is drawn');

            foreach (['../.env', 'https://example.com/icon.png', 'assets/vendor/vendor.json', ''] as $unsafe) {
                $pwa = new Pwa($use(['logo' => 'uploads/' . $names['logo'], 'appIcon' => $unsafe]), $this->folder);
                $this->assertSame(realpath(FCPATH . 'uploads/' . $names['logo']), $pwa->logoFile(), 'unusable app icon "' . $unsafe . '": the logo');
            }
        } finally {
            foreach ($names as $name) {
                unlink(FCPATH . 'uploads/' . $name);
            }
        }
    }

    public function testTheHeadLinksTheFaviconAndTheAppIcon(): void
    {
        $render = static function (array $values): string {
            $property = new \ReflectionProperty(\Modules\Core\Theme\ThemeManager::class, 'options');
            $manager  = service('theme');
            $manager->reset();
            $options = new \Modules\Core\Theme\ThemeOptions('default', $manager->manifests('public'), static fn (string $key): mixed => $values[$key] ?? null);
            $property->setValue($manager, ['public' => $options, 'backend' => $options]);

            return view(theme_resolve('partials/head'), [], ['saveData' => false]);
        };

        $html = $render(['favicon' => 'uploads/icon.png', 'appIcon' => 'https://cdn.example.com/app.png']);
        $this->assertStringContainsString('<link rel="icon" href="' . base_url('uploads/icon.png') . '">', $html);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="https://cdn.example.com/app.png">', $html, 'without the PWA: the app icon itself');

        foreach (['javascript:alert(1)', '../.env', '//evil.example/x.png', 'a"onerror="x.png'] as $unsafe) {
            $html = $render(['favicon' => $unsafe, 'appIcon' => $unsafe]);
            $this->assertStringNotContainsString('rel="icon"', $html, $unsafe);
            $this->assertStringNotContainsString('rel="apple-touch-icon"', $html, $unsafe);
        }

        $this->assertStringNotContainsString('rel="icon"', $render([]), 'no favicon option: the browser asks for /favicon.ico');
    }
}
