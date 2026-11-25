<?php

declare(strict_types=1);

namespace Modules\News\Tests;

use CodeIgniter\Shield\Entities\User;
use Modules\Core\Frontend\RichTextEditor;
use Modules\Core\Storage\Picker\PickerField;
use Modules\Core\Storage\StoredFile;
use Modules\Core\Tests\StorageFixturesTrait;
use Modules\Core\Tests\TestUploadedFile;
use Modules\News\Storage\CoverUploads;

/**
 * The file picker in News (session 23): the cover field and "Insert image" in the body. An image
 * inserted in a body is stored through the Storage service (public disk, `modules/news`,
 * re-encoded); external images are removed from the body; a forged cover key is refused.
 *
 * @internal
 */
final class NewsPickerTest extends NewsTestCase
{
    use StorageFixturesTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeStorage();
    }

    protected function tearDown(): void
    {
        $this->removeStorageRoot();
        parent::tearDown();
    }

    private function pick(User $user, PickerField $field, string $contents, string $name): StoredFile
    {
        $signedIn = auth()->loggedIn();

        if (! $signedIn) {
            auth()->login($user);
        }

        try {
            return PickerField::backend()->upload($user, $field->scope($user), TestUploadedFile::fromContents($contents, $name));
        } finally {
            if (! $signedIn) {
                auth()->logout();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function form(string $title, string $body, ?string $cover = null): array
    {
        return [
            'kp_tr' => ['ca' => ['title' => $title, 'summary' => '', 'body' => $body]],
            'type'  => 'article', 'priority' => 'info', 'visibility' => 'public', 'cover' => $cover ?? '',
        ];
    }

    public function testTheFormHasThePickerAndTheEditorsInsertImage(): void
    {
        $this->createUser('ana@example.com', 'editor');
        $this->login('ana@example.com');
        $page = $this->body('GET', 'backend/news/posts/add');

        $this->assertStringContainsString('data-component="core/forms/file-picker"', $page, 'The cover is a file picker.');
        $this->assertStringContainsString('"images":{"name":"image"', $page, 'The editor gets the picker for Insert image.');
        $this->assertStringNotContainsString('type="file" name="cover"', $page);
    }

    public function testAnImageInsertedInTheBodyIsStoredThroughTheStorageService(): void
    {
        $ana   = $this->createUser('ana@example.com', 'editor');
        $this->login('ana@example.com');
        $image = $this->pick($ana, RichTextEditor::images('news'), self::jpegWithMetadata(), 'Photo.JPG');

        $this->assertSame('public', $image->disk);
        $this->assertStringStartsWith('modules/news/', $image->path);
        $this->seeInDatabase('stored_files', ['id' => $image->id, 'uploaded_by' => $ana->id]);
        $this->assertStringNotContainsString('Exif', (string) service('storage')->disk('public')->read($image->path), 'Re-encoded: no metadata.');

        $url = service('storage')->url($image);
        $this->send('POST', 'backend/news/posts/add', $this->form('Amb imatge', '<p>Mireu:</p><p><img src="' . $url . '" alt="Una foto"><img src="https://tracker.example/p.gif" alt="x"></p>'));

        $post = db_connect()->table('news')->where('slug', 'amb-imatge')->get()->getRowArray();
        $this->assertIsArray($post);
        $body = (string) db_connect()->table('news_translations')->where('news_id', $post['id'])->get()->getRowArray()['body'];
        $this->assertStringContainsString('<img src="' . $url . '" alt="Una foto">', $body, 'The site\'s own image is kept.');
        $this->assertStringNotContainsString('tracker.example', $body, 'External images are removed.');
    }

    public function testTheCoverComesFromThePicker(): void
    {
        $ana    = $this->createUser('ana@example.com', 'editor');
        $this->login('ana@example.com');
        $cover  = $this->pick($ana, CoverUploads::pickerField('cover'), self::png(), 'cover.png');
        $secret = service('storage')->storeContents(self::png(), 'secret.png', 'private', 'shared/secret');

        $this->send('POST', 'backend/news/posts/add', $this->form('Amb portada', '', $cover->key));
        $post = db_connect()->table('news')->where('slug', 'amb-portada')->get()->getRowArray();
        $this->assertIsArray($post);
        $this->assertSame($cover->key, $post['cover']);

        // A forged key (a private file, garbage) is refused on add and on edit.
        $this->send('POST', 'backend/news/posts/add', $this->form('Forjada', '', $secret->key));
        $this->dontSeeInDatabase('news', ['slug' => 'forjada']);

        $this->send('POST', 'backend/news/posts/edit/' . $this->code((int) $post['id']), $this->form('Amb portada', '', $secret->key));
        $this->assertSame($cover->key, $this->row((int) $post['id'])['cover']);

        $this->send('POST', 'backend/news/posts/edit/' . $this->code((int) $post['id']), $this->form('Amb portada', '', ''));
        $this->assertNull($this->row((int) $post['id'])['cover'], 'The cover can be removed.');
    }

    public function testTheReaderAndThePublicPostPrintTheCoverWithItsVariants(): void
    {
        $ana   = $this->createUser('ana@example.com', 'editor');
        $image = imagecreatetruecolor(1200, 800);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 110, 190));
        ob_start();
        imagejpeg($image);
        $this->login('ana@example.com');
        $cover = $this->pick($ana, CoverUploads::pickerField('cover'), (string) ob_get_clean(), 'cover.jpg');
        $id    = $this->newsPost('Amb foto', ['cover' => $cover->key, 'visibility' => 'public']);

        foreach (['backend/news/read/' . $this->code($id), 'news/amb-foto'] as $path) {
            $page = $this->body('GET', $path);
            $this->assertStringContainsString('-w400.', $page, $path . ': the variants in srcset.');
            $this->assertStringContainsString('width="1200" height="800"', $page, $path);
        }
    }
}
