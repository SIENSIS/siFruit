<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\I18n\Time;
use CodeIgniter\Test\Filters\CITestStreamFilter;
use GdImage;
use Modules\Core\Modules\ModuleSynchronizer;
use Modules\Core\Security\HtmlSanitizer;
use Modules\Core\Storage\ImageOptimizer;
use Modules\Core\Storage\ImageReencoder;
use Modules\Core\Storage\Picker\StorageBackend;
use Modules\Core\Storage\Picker\UploadToken;
use Modules\Core\Storage\Storage;
use Modules\Core\Storage\StoredFile;
use Modules\Core\Storage\UploadRefused;
use Modules\Core\Storage\UploadRules;
use Modules\Core\System\ReportRow;
use Modules\Core\System\SystemEnvironment;
use Modules\Core\System\SystemReport;
use Modules\Core\Tasks\OptimizeImagesTask;

/**
 * Session 54 (D-54-01): dated module folders, images scaled down on upload, responsive variants,
 * `ui_image()` and rich text, `storage:optimize`, the weekly task and the System information rows.
 *
 * @internal
 */
final class ImageOptimisationTest extends RbacTestCase
{
    use StorageFixturesTrait;

    private Storage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Time::setTestNow('2026-11-23 10:00:00');
        $this->storage = $this->makeStorage();
        cache()->delete(SystemReport::IMAGES_CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        $this->removeStorageRoot();
        parent::tearDown();
    }

    // A. Dated folders --------------------------------------------------------------------------

    public function testModuleUploadsGoToDatedFolders(): void
    {
        $file = $this->storage->storeContents(self::png(), 'a.png', 'public', 'modules/news');

        $this->assertMatchesRegularExpression('#^modules/news/2026/11/[a-f0-9]{32}\.png$#', $file->path);
        $this->assertSame('modules/news', Storage::baseFolder($file->path));
        $this->assertTrue(Storage::isFileOf($file->path, 'modules/news'));
        $this->assertFalse(Storage::isFileOf($file->path, 'modules'));
        $this->assertSame(0755, fileperms(dirname($this->storage->disk('public')->localPath($file->path))) & 0777, 'Date folders get the disk permissions.');
    }

    public function testUserFoldersAreNotDated(): void
    {
        $personal = $this->storage->storeContents(self::pdf(), 'a.pdf', 'private', 'users/7');
        $shared   = $this->storage->storeContents(self::pdf(), 'b.pdf', 'private', 'shared/Secretaria');
        $explicit = $this->storage->storeContents(self::pdf(), 'c.pdf', 'private', 'modules/news', null, false);

        $this->assertMatchesRegularExpression('#^users/7/[a-f0-9]{32}\.pdf$#', $personal->path);
        $this->assertMatchesRegularExpression('#^shared/Secretaria/[a-f0-9]{32}\.pdf$#', $shared->path);
        $this->assertMatchesRegularExpression('#^modules/news/[a-f0-9]{32}\.pdf$#', $explicit->path);
        $this->assertSame('shared/Secretaria', Storage::baseFolder($shared->path));
    }

    public function testDatedFoldersCanBeTurnedOff(): void
    {
        $this->storage->config()->datedFolders = null;
        $file                                  = $this->storage->storeContents(self::png(), 'a.png', 'public', 'modules/news');

        $this->assertMatchesRegularExpression('#^modules/news/[a-f0-9]{32}\.png$#', $file->path);
    }

    public function testThePickerBackendFindsFilesInDatedFolders(): void
    {
        $user = $this->createUser('ana@example.com');
        $this->actAs($user);
        $file  = $this->storage->storeContents(self::png(), 'a.png', 'public', 'modules/news');
        $token = new UploadToken((int) $user->id, 'both', 'public', 'modules/news', [], 0, 1, [], true, time() + 60);

        $listing = (new StorageBackend())->browse($user, $token, 'modules/news');

        $this->assertNotNull($listing);
        $this->assertSame([$file->id], array_map(static fn (StoredFile $f): int => $f->id, $listing['files']));
        $this->assertTrue((new StorageBackend())->canChoose($user, $token, $file));
    }

    // B. Resize on upload -----------------------------------------------------------------------

    public function testLargePhotosAreScaledDownAndTheOriginalIsNotKept(): void
    {
        $file = $this->storage->storeContents(self::jpeg(4000, 3000), 'photo.jpg', 'private', 'users/1');

        $this->assertSame([1920, 1440], [$file->width, $file->height]);
        $this->assertSame([1920, 1440], array_slice((array) getimagesizefromstring($this->storage->disk('private')->read($file->path)), 0, 2));
        $this->assertSame(strlen($this->storage->disk('private')->read($file->path)), $file->size);
        $this->assertCount(1, $this->storage->disk('private')->list('users/1', true), 'Only the reduced image is stored.');
    }

    public function testOrientationIsAppliedBeforeScaling(): void
    {
        $file = $this->storage->storeContents(self::jpeg(4000, 3000, 6), 'portrait.jpg', 'private', 'users/1');

        $this->assertSame([1440, 1920], [$file->width, $file->height]);
    }

    public function testSmallImagesAreNotScaledUp(): void
    {
        $file = $this->storage->storeContents(self::jpeg(1000, 600), 'a.jpg', 'private', 'users/1');

        $this->assertSame([1000, 600], [$file->width, $file->height]);
    }

    public function testPngTransparencyIsKept(): void
    {
        $image = imagecreatetruecolor(2400, 1200);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 1000, 400, 1400, 800, (int) imagecolorallocate($image, 200, 0, 0));
        ob_start();
        imagepng($image);

        $file   = $this->storage->storeContents((string) ob_get_clean(), 'logo.png', 'private', 'users/1');
        $stored = imagecreatefromstring($this->storage->disk('private')->read($file->path));

        $this->assertSame([1920, 960], [$file->width, $file->height]);
        $this->assertInstanceOf(GdImage::class, $stored);
        $this->assertSame(127, (imagecolorat($stored, 5, 5) >> 24) & 0x7F, 'The corner stays transparent.');
        $this->assertSame(0, (imagecolorat($stored, 960, 480) >> 24) & 0x7F, 'The middle stays opaque.');
    }

    public function testAFieldMayOnlyNarrowTheLimit(): void
    {
        $narrow = $this->storage->storeContents(self::jpeg(3000, 1500), 'a.jpg', 'private', 'users/1', UploadRules::make('jpg')->maxDimension(400));
        $wide   = $this->storage->storeContents(self::jpeg(3000, 1500), 'b.jpg', 'private', 'users/1', UploadRules::make('jpg')->maxDimension(5000));

        $this->assertSame([400, 200], [$narrow->width, $narrow->height]);
        $this->assertSame([1920, 960], [$wide->width, $wide->height]);
    }

    public function testImagesStoredWithoutReencodingAreNotResized(): void
    {
        $file = $this->storage->storeContents(self::jpeg(2400, 1200), 'raw.jpg', 'private', 'users/1', UploadRules::make('jpg', null, false));

        $this->assertSame([2400, 1200], [$file->width, $file->height]);
        $this->seeInDatabase('stored_files', ['id' => $file->id, 'reencoded' => 0]);
    }

    public function testImagesTooLargeForTheMemoryAreRefusedWithAReason(): void
    {
        $this->assertFalse(ImageReencoder::fitsInMemory(10000, 10000, 64 * 1024 * 1024));
        $this->assertTrue(ImageReencoder::fitsInMemory(100, 100, -1));

        $previous = (string) ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage() + 24 * 1024 * 1024));

        try {
            $this->storage->storeContents(self::png(3000, 3000), 'big.png', 'private', 'users/1');
            $this->fail('Accepted.');
        } catch (UploadRefused $e) {
            $this->assertSame('memory', $e->reason());
            $this->assertStringContainsString('3000×3000', $e->getMessage());
        } finally {
            ini_set('memory_limit', $previous);
        }
    }

    public function testTheGlobalLimitComesFromTheSettingWithAMinimum(): void
    {
        (new ModuleSynchronizer())->sync();
        $this->assertSame(1920, \Modules\Core\Storage\UploadValidator::globalMaxDimension());

        service('settings')->set('Core.imageMaxDimension', 100);
        $this->assertSame(320, \Modules\Core\Storage\UploadValidator::globalMaxDimension());
    }

    // C. Variants -------------------------------------------------------------------------------

    public function testPublicImagesGetVariantsNarrowerThanTheImage(): void
    {
        $file     = $this->storage->storeContents(self::jpeg(1200, 800), 'a.jpg', 'public', 'modules/news');
        $variants = $this->storage->variants()->of($file->id);

        $this->assertSame([400, 800], array_column($variants, 'width'), 'No 1600 copy of a 1200 px image.');
        $this->assertSame([267, 533], array_column($variants, 'height'));

        foreach ($variants as $variant) {
            $this->assertMatchesRegularExpression('#^modules/news/2026/11/[a-f0-9]{32}-w' . $variant['width'] . '\.webp$#', $variant['path']);
            $this->assertSame('webp', $variant['format']);
            $this->assertTrue($this->storage->disk('public')->exists($variant['path']));
            $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($this->storage->disk('public')->read($variant['path']))[2] ?? null);
        }
    }

    public function testPrivateFilesAndSmallImagesGetNoVariants(): void
    {
        $private = $this->storage->storeContents(self::jpeg(1200, 800), 'a.jpg', 'private', 'users/1');
        $small   = $this->storage->storeContents(self::png(), 'b.png', 'public', 'modules/news');

        $this->assertSame([], $this->storage->variants()->of($private->id));
        $this->assertSame([], $this->storage->variants()->of($small->id));
        $this->seeNumRecords(0, 'stored_file_variants', []);
    }

    public function testFlatGraphicsKeepTheSmallerFormat(): void
    {
        $image = imagecreatetruecolor(1200, 600);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 32, 96, 168));
        ob_start();
        imagepng($image);

        $file = $this->storage->storeContents((string) ob_get_clean(), 'flat.png', 'public', 'modules/pages');

        foreach ($this->storage->variants()->of($file->id) as $variant) {
            $this->assertStringEndsWith('.' . $variant['format'], $variant['path']);
            $this->assertLessThanOrEqual($file->size, $variant['size'], 'A variant never weighs more than the image.');
        }
    }

    public function testVariantsUseTheOwnFormatWithoutWebp(): void
    {
        $this->assertContains(\Modules\Core\Storage\ImageVariants::format('png'), ['webp', 'png']);
        $this->assertSame(function_exists('imagewebp') ? 'webp' : 'jpg', \Modules\Core\Storage\ImageVariants::format('jpeg'));
    }

    public function testVariantsAreDeletedWithTheFile(): void
    {
        $file  = $this->storage->storeContents(self::jpeg(1200, 800), 'a.jpg', 'public', 'modules/news');
        $paths = array_column($this->storage->variants()->of($file->id), 'path');

        $this->storage->delete($file);

        foreach ($paths as $path) {
            $this->assertFalse($this->storage->disk('public')->exists($path));
        }

        $this->seeNumRecords(0, 'stored_file_variants', []);
        $this->assertSame([], array_filter($this->storage->disk('public')->list('', true), static fn (array $item): bool => $item['type'] === 'file'));
    }

    public function testNoRouteGeneratesImagesOnRequest(): void
    {
        $routes = service('routes')->loadRoutes()->getRoutes('GET');
        $this->assertNotEmpty($routes);

        foreach ($routes as $from => $to) {
            $this->assertDoesNotMatchRegularExpression('#(resize|variant|scale|-w\(|/w/)#i', (string) $from, 'No resize endpoint.');
        }
    }

    // ui_image() and rich text ------------------------------------------------------------------

    public function testUiImagePrintsTheVariants(): void
    {
        $file = $this->storage->storeContents(self::jpeg(1800, 1200), 'a.jpg', 'public', 'modules/news');
        $html = ui_image($file->key, ['alt' => 'A "quoted" <alt>', 'class' => 'img-fluid', 'sizes' => '50vw']);
        $base = base_url('uploads/' . substr($file->path, 0, -4));

        $this->assertStringContainsString('src="' . $base . '-w1600.webp"', $html);
        $this->assertStringContainsString('srcset="' . $base . '-w400.webp 400w, ' . $base . '-w800.webp 800w, ' . $base . '-w1600.webp 1600w, ' . base_url('uploads/' . $file->path) . ' 1800w"', $html);
        $this->assertStringContainsString('sizes="50vw"', $html);
        $this->assertStringContainsString('width="1800" height="1200"', $html);
        $this->assertStringContainsString('loading="lazy" decoding="async"', $html);
        $this->assertStringContainsString('alt="A &quot;quoted&quot; &lt;alt&gt;"', $html);
        $this->assertStringNotContainsString('style=', $html);

        $eager = ui_image($file->id, ['lazy' => false]);
        $this->assertStringNotContainsString('loading=', $eager);
        $this->assertSame($html, ui_image(base_url('uploads/' . $file->path), ['alt' => 'A "quoted" <alt>', 'class' => 'img-fluid', 'sizes' => '50vw']), 'A site URL resolves to the stored file.');
        $this->assertSame($base . '-w1600.webp', ui_image_url($file));
        $this->assertSame($base . '-w800.webp', ui_image_url($file, 1000));
    }

    public function testUiImageFallsBack(): void
    {
        $small = $this->storage->storeContents(self::png(), 'b.png', 'public', 'modules/news');

        $this->assertSame('<img src="' . base_url('uploads/' . $small->path) . '" alt="" width="8" height="6" loading="lazy" decoding="async">', ui_image($small));
        $this->assertSame('<img src="https://example.org/a.jpg?x=1&amp;y=2" alt="" loading="lazy" decoding="async">', ui_image('https://example.org/a.jpg?x=1&y=2'));
        $this->assertSame('', ui_image('javascript:alert(1)'));
        $this->assertSame('', ui_image(str_repeat('a', 32)), 'An unknown key prints nothing.');
        $this->assertSame('', ui_image(null));
    }

    public function testRichTextImagesGetTheServersSrcsetOnly(): void
    {
        $file = $this->storage->storeContents(self::jpeg(1800, 1200), 'a.jpg', 'public', 'modules/news');
        $url  = base_url('uploads/' . $file->path);

        $html = HtmlSanitizer::clean('<p><img src="' . $url . '" alt="Pati" srcset="https://evil.example/x.jpg 1w" sizes="1px" loading="eager" onerror="x()" width="5" height="5"></p>');

        $this->assertStringContainsString('srcset="' . base_url('uploads/' . substr($file->path, 0, -4)) . '-w400.webp 400w', $html);
        $this->assertStringContainsString('width="1800" height="1200"', $html);
        $this->assertStringContainsString('alt="Pati"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('1px', $html);
        $this->assertSame($html, HtmlSanitizer::clean($html), 'Stable when cleaned again.');

        $plain = HtmlSanitizer::clean('<p><img src="/uploads/unknown.jpg" alt="x" srcset="/a.jpg 1w" loading="eager"></p>');
        $this->assertStringNotContainsString('srcset', $plain, 'Images without variants keep no client attribute.');
        $this->assertStringNotContainsString('loading', $plain);
    }

    // D. Optimiser, command, task, report -------------------------------------------------------

    public function testTheOptimiserShrinksExistingImagesInPlace(): void
    {
        $file = $this->legacyImage(3000, 2000);
        $url  = $this->storage->url($file);

        $dry = (new ImageOptimizer($this->storage, 1920))->run(['dryRun' => true]);
        $this->assertSame(1, $dry['processed']);
        $this->assertSame($file->checksum, $this->storage->findById($file->id)?->checksum, 'A dry run writes nothing.');
        $this->seeNumRecords(0, 'stored_file_variants', []);

        $run    = (new ImageOptimizer($this->storage, 1920))->run();
        $stored = $this->storage->findById($file->id);

        $this->assertSame([1, 1, 3], [$run['processed'], $run['resized'], $run['variants']]);
        $this->assertNotNull($stored);
        $this->assertSame([1920, 1280], [$stored->width, $stored->height]);
        $this->assertSame([$file->key, $file->path, $url], [$stored->key, $stored->path, $this->storage->url($stored)], 'Same key, path and URL.');
        $contents = $this->storage->disk('public')->read($stored->path);
        $this->assertSame([hash('sha256', $contents), strlen($contents)], [$stored->checksum, $stored->size]);
        $this->assertSame([400, 800, 1600], array_column($this->storage->variants()->of($file->id), 'width'));

        $again = (new ImageOptimizer($this->storage, 1920))->run();
        $this->assertSame([0, []], [$again['processed'], $again['failed']], 'Idempotent.');
    }

    public function testTheOptimiserSkipsRawFilesAndRemembersFailures(): void
    {
        $raw = $this->storage->storeContents(self::jpeg(2400, 1200), 'raw.jpg', 'private', 'users/1', UploadRules::make('jpg', null, false));
        $bad = $this->legacyImage(3000, 2000);
        $this->storage->disk('public')->write($bad->path, 'not an image any more');

        $first = (new ImageOptimizer($this->storage, 1920))->run();

        $this->assertSame(0, $first['processed']);
        $this->assertSame([$bad->id], array_column($first['failed'], 'id'));
        $this->assertSame([2400, 1200], [$this->storage->findById($raw->id)?->width, $this->storage->findById($raw->id)?->height]);
        $this->assertNotNull(db_connect()->table('stored_files')->where('id', $bad->id)->get()->getRowArray()['optimize_failed_at'] ?? null);

        $second = (new ImageOptimizer($this->storage, 1920))->run();
        $this->assertSame([0, []], [$second['processed'], $second['failed']], 'Not retried in a loop.');
        $this->assertSame(1, (new ImageOptimizer($this->storage, 1920))->pending()['failed']);
    }

    public function testTheCommandRunsAndLogsOnce(): void
    {
        $this->legacyImage(2400, 1600);
        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();

        try {
            command('storage:optimize --dry-run');
            $this->seeNumRecords(0, 'activity_log', ['action' => 'images_optimized']);
            command('storage:optimize');
            command('storage:optimize');
            $output = CITestStreamFilter::$buffer;
        } finally {
            CITestStreamFilter::removeOutputFilter();
        }

        $this->assertStringContainsString(lang('Core.optimize.dryRun'), $output);
        $this->assertStringContainsString(lang('Core.optimize.none'), $output);
        $this->seeNumRecords(1, 'activity_log', ['module' => 'Core', 'action' => 'images_optimized']);
    }

    public function testTheTaskIsDeclaredAndProcessesOneBatchPerRun(): void
    {
        $this->assertContains(OptimizeImagesTask::class, (new \Modules\Core\Config\Module())->tasks);
        $this->assertSame('17 3 * * 0', (new OptimizeImagesTask())->schedule()->expression());

        $this->legacyImage(2400, 1600);
        $this->legacyImage(2400, 1600);
        $this->legacyImage(2400, 1600);
        $config                 = $this->storage->config();
        $config->optimizeBatch  = 2;

        $result = (new OptimizeImagesTask(new ImageOptimizer($this->storage, 1920), $config))->run();
        $this->assertFalse($result->failed());
        $this->assertSame(1, (new ImageOptimizer($this->storage, 1920))->pending()['oversized'], 'One left for the next run.');
        $this->seeNumRecords(1, 'activity_log', ['action' => 'images_optimized']);

        (new OptimizeImagesTask(new ImageOptimizer($this->storage, 1920), $config))->run();
        $this->assertSame(0, (new ImageOptimizer($this->storage, 1920))->pending()['oversized']);
        $this->seeNumRecords(2, 'activity_log', ['action' => 'images_optimized']);
    }

    public function testSystemInformationReportsWaitingImages(): void
    {
        $this->assertSame(ReportRow::OK, $this->imagesRow()->status);

        $this->legacyImage(2400, 1600);
        $this->assertSame(ReportRow::WARNING, $this->imagesRow()->status);
        $this->assertStringContainsString('2026-11-29 03:17', $this->imagesRow()->note, 'Declared but not yet synced: its first run.');

        db_connect()->table('scheduled_tasks')->insert([
            'class' => OptimizeImagesTask::class, 'module' => 'Core', 'expression' => '17 3 * * 0', 'active' => 1,
            'next_run_at' => '2026-11-29 02:17:00', 'last_run_at' => '2026-11-22 02:17:00', 'created_at' => '2026-11-01 00:00:00',
        ]);
        $row = $this->imagesRow();
        $this->assertSame(ReportRow::WARNING, $row->status);
        $this->assertStringContainsString('2026-11-29 02:17:00', $row->note);
        $this->assertStringNotContainsString('storage:optimize', $row->note);

        db_connect()->table('scheduled_tasks')->where('class', OptimizeImagesTask::class)->update(['last_run_at' => '2026-10-01 02:17:00']);
        $this->assertStringContainsString('storage:optimize', $this->imagesRow()->note, 'Not run for two weeks.');

        db_connect()->table('scheduled_tasks')->where('class', OptimizeImagesTask::class)->update(['last_run_at' => '2026-11-22 02:17:00', 'active' => 0]);
        $this->assertStringContainsString('storage:optimize', $this->imagesRow()->note, 'Disabled.');
    }

    private function imagesRow(): ReportRow
    {
        SystemReport::refresh();
        $env = new class () extends SystemEnvironment {
            public function now(): int
            {
                return (int) strtotime('2026-11-23 10:00:00 UTC');
            }
        };

        foreach (SystemReport::build($env)->sections() as $section) {
            foreach ($section->key === 'storage' ? $section->rows() : [] as $row) {
                if ($row->label() === lang('System.rows.imagesPending')) {
                    return $row;
                }
            }
        }

        $this->fail('No "Images to optimise" row.');
    }

    /**
     * An image stored before session 54: on disk as it was uploaded, no variants.
     */
    private function legacyImage(int $width, int $height): StoredFile
    {
        $contents = self::jpeg($width, $height);
        $path     = 'modules/news/' . bin2hex(random_bytes(16)) . '.jpg';
        $this->storage->disk('public')->write($path, $contents);
        $row = [
            'file_key' => bin2hex(random_bytes(16)), 'disk' => 'public', 'path' => $path, 'original_name' => 'old.jpg', 'extension' => 'jpg',
            'mime'     => 'image/jpeg', 'size' => strlen($contents), 'checksum' => hash('sha256', $contents), 'width' => $width, 'height' => $height,
            'uploaded_by' => null, 'created_at' => '2025-01-01 00:00:00',
        ];
        db_connect()->table('stored_files')->insert($row);

        return StoredFile::fromRow(['id' => (int) db_connect()->insertID()] + $row);
    }

    /**
     * A JPEG with some detail; `$orientation` adds an EXIF Orientation tag.
     */
    private static function jpeg(int $width, int $height, int $orientation = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 110, 190));
        imagefilledrectangle($image, 0, 0, intdiv($width, 3), intdiv($height, 2), (int) imagecolorallocate($image, 220, 180, 40));
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = (string) ob_get_clean();

        if ($orientation === 1) {
            return $jpeg;
        }

        $tiff = 'II*' . "\0" . pack('V', 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0);
        $app1 = "\xFF\xE1" . pack('n', strlen('Exif' . "\0\0" . $tiff) + 2) . 'Exif' . "\0\0" . $tiff;

        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }
}
