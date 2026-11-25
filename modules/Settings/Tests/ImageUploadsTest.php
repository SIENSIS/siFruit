<?php

declare(strict_types=1);

namespace Modules\Settings\Tests;

use CodeIgniter\HTTP\Files\UploadedFile;
use Modules\Core\Tests\TestUploadedFile;
use Modules\Settings\Forms\Field;
use Modules\Settings\Forms\FormGenerator;
use Modules\Settings\Forms\ImageUploads;

/**
 * `image` settings (D-56-02): the uploaded file is stored through the Storage service on the public
 * disk under `uploads/settings/<folder>/`, refused files store nothing, a replaced upload is
 * deleted unless another setting uses it, and a theme's own images are never deleted.
 *
 * @internal
 */
final class ImageUploadsTest extends SettingsTestCase
{
    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Storage and settings built by earlier tests may carry their own upload rules.
        \Config\Services::resetSingle('storage');
        \Config\Services::resetSingle('settings');
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(64, 64);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 160, 90));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function field(mixed $value = '', mixed $default = ''): Field
    {
        return new Field('option-favicon', 'Theme.favicon', 'image', 'ThemeDefault.options.favicon', $value, $default);
    }

    /**
     * @param array<string, UploadedFile> $files
     *
     * @return \Closure(string): (UploadedFile|null)
     */
    private static function files(array $files): \Closure
    {
        return static fn (string $name): ?UploadedFile => $files[$name] ?? null;
    }

    public function testAnUploadedImageIsStoredOnThePublicDiskAndItsPathKept(): void
    {
        $images = new ImageUploads();
        $result = $images->apply([$this->field()], self::files(['option-favicon-file' => TestUploadedFile::fromContents(self::png(), 'icon.png')]), 'theme-default');

        $this->assertSame([], $result['errors']);
        $path = $result['values']['Theme.favicon'] ?? '';
        $this->assertMatchesRegularExpression('#^uploads/settings/theme-default/[a-f0-9]{32}\.png$#', $path, 'random name, no date folder, relative to public/');
        $this->assertFileExists(FCPATH . $path);
        $this->created[] = FCPATH . $path;
        $this->assertSame(1, db_connect()->table('stored_files')->where('path', substr($path, strlen('uploads/')))->countAllResults());
    }

    public function testARefusedFileStoresNothing(): void
    {
        $before = db_connect()->table('stored_files')->countAllResults();
        $result = (new ImageUploads())->apply([$this->field()], self::files(['option-favicon-file' => TestUploadedFile::fromContents('<?php echo 1;', 'icon.png')]), 'theme-default');

        $this->assertArrayHasKey('option-favicon', $result['errors']);
        $this->assertSame([], $result['values']);
        $this->assertSame($before, db_connect()->table('stored_files')->countAllResults());
    }

    public function testReadOnlyFieldsAndOtherTypesAreIgnored(): void
    {
        $upload   = self::files(['option-favicon-file' => TestUploadedFile::fromContents(self::png(), 'icon.png')]);
        $readOnly = new Field('option-favicon', 'Theme.favicon', 'image', 'x', '', '', readOnly: true);
        $string   = new Field('option-favicon', 'Theme.favicon', 'string', 'x', '', '');

        $this->assertSame(['values' => [], 'errors' => []], (new ImageUploads())->apply([$readOnly, $string], $upload, 'theme-default'));
    }

    public function testAReplacedUploadIsDeletedUnlessStillUsed(): void
    {
        $images = new ImageUploads();
        $old    = $images->apply([$this->field()], self::files(['option-favicon-file' => TestUploadedFile::fromContents(self::png(), 'old.png')]), 'theme-default')['values']['Theme.favicon'];
        $this->created[] = FCPATH . $old;

        // Still used by another theme: kept.
        service('settings')->set('Theme.favicon', $old, 'theme:other');
        $images->forgetReplaced([$this->field($old)], ['Theme.favicon' => 'uploads/settings/theme-default/new.png']);
        $this->assertFileExists(FCPATH . $old);

        // No longer used: deleted with its record.
        service('settings')->forget('Theme.favicon', 'theme:other');
        $images->forgetReplaced([$this->field($old)], ['Theme.favicon' => 'uploads/settings/theme-default/new.png']);
        $this->assertFileDoesNotExist(FCPATH . $old);
        $this->assertSame(0, db_connect()->table('stored_files')->where('path', substr($old, strlen('uploads/')))->countAllResults());

        // A theme's own image is never deleted.
        $own = 'themes/tutorial-demo/img/logo.svg';
        $images->forgetReplaced([$this->field($own)], ['Theme.favicon' => '']);
        $this->assertFileExists(ROOTPATH . 'themes/tutorial-demo/Assets/img/logo.svg');
    }

    public function testDiscardDeletesWhatWasStored(): void
    {
        $images = new ImageUploads();
        $path   = $images->apply([$this->field()], self::files(['option-favicon-file' => TestUploadedFile::fromContents(self::png(), 'icon.png')]), 'theme-default')['values']['Theme.favicon'];
        $images->discard();

        $this->assertFileDoesNotExist(FCPATH . $path);
    }

    public function testTheFieldShowsThePreviewAndTheValueIsNeverTakenFromThePost(): void
    {
        $field = $this->field('themes/tutorial-demo/img/logo.svg', '');
        $html  = (new FormGenerator())->field($field);
        $this->assertStringContainsString('src="' . esc(base_url('themes/tutorial-demo/img/logo.svg'), 'attr') . '"', $html);
        $this->assertStringContainsString('name="option-favicon-file"', $html);
        $this->assertStringContainsString('name="option-favicon-reset"', $html);

        $result = (new FormGenerator())->validate([$field], ['option-favicon' => '../.env']);
        $this->assertSame(['Theme.favicon' => 'themes/tutorial-demo/img/logo.svg'], $result['values']);
        $this->assertSame(['Theme.favicon' => ''], (new FormGenerator())->validate([$field], ['option-favicon-reset' => '1'])['values']);

        foreach (['javascript:alert(1)', '../.env', '//evil.example/x.png'] as $unsafe) {
            $this->assertSame('', Field::imageUrl($unsafe), $unsafe);
        }
    }
}
