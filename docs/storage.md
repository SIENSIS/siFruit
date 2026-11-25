# File storage and uploads

The Core's **Storage service** (`service('storage')`, `Modules\Core\Storage\Storage`) is the only
way to write files. It has two disks over Flysystem:

| Disk | Folder | Served by | Permissions |
|---|---|---|---|
| `public` | `public/uploads` | the web server (`/uploads/…`), with no execution (`docs/web-server.md`) | folders `0755`, files `0644` |
| `private` | `writable/storage` | only `files/private/{key}`, after the access rule | folders `0750`, files `0640` |

Disks, permissions, forbidden extensions, image limits and the access rule are configured in
`app/Config/Storage.php`. Modules never use these paths: they call the service, so another adapter
(S3, SFTP) can be added later. Decisions: D-13-01 … D-13-05 in `docs/decisions.md`.

The one exception is "upload a backup file" (Backup module, D-27-05): it accepts only files with a
valid backup header, stores them in `writable/backups` (outside the disks, never served) with a
generated name, and nothing in them is used before they are decrypted and authenticated
(`docs/backup-restore.md`).

## Storing an upload

A **form field** that takes a file uses the Core's file picker (`PickerField`, signed upload
tokens, `files/picker/upload`; docs/files.md, "File picker"), which calls the Storage service
for you. The API below is for module code that stores files itself.

```php
use Modules\Core\Storage\UploadRefused;
use Modules\Core\Storage\UploadRules;

try {
    $file = service('storage')->store(
        $this->request->getFile('attachment'),     // UploadedFile
        'private',                                  // disk
        'modules/news/' . $postId,                  // folder inside the disk
        UploadRules::make('pdf,jpg,png', 5 * 1024 * 1024), // this field's rules
    );
} catch (UploadRefused $e) {
    return redirect()->back()->with('error', $e->getMessage()); // translated, already logged
}

$file->key;          // random id of files/private/{key}
$file->originalName; // "Informe final.pdf" (metadata only)
service('storage')->url($file);       // site_url('files/private/' . key), or the public URL
service('storage')->url($file, true); // ?download=1
```

`storeContents($bytes, 'report.csv', 'private', 'modules/reports')` stores generated contents
through the same rules. `find($key)`, `findById($id)` and `delete($file)` complete the API;
`disk('private')` gives the disk (`write`, `read`, `readStream`, `exists`, `delete`, `move`,
`copy`, `list`, `url` for the public disk) for module files that are not uploads.

`store()` does, in order:

1. normalises the folder (`PathNormalizer`: no `..`, `.`, absolute paths, backslashes, control
   characters or hidden segments such as `.htaccess`);
2. checks the upload rules (`UploadValidator`, below); a refusal throws `UploadRefused`
   (`reason()`: `invalid`, `empty`, `too_large`, `type`, `forbidden`, `mime`, `image`, `svg`,
   `content`, `memory`; message `Core.storage.refused.<reason>`) and writes a `security` entry
   `Core`/`upload_refused` (original name, reason, disk, folder) to the activity log;
3. writes the file with a random name (`<folder>/<32 hex>.<ext>`) and the disk's permissions; in a
   **module folder** (`modules/<module>/…`) the folder gets a date subfolder first (below);
4. records it in `stored_files` (key, disk, path, original name, extension, MIME type, size,
   SHA-256, image size, `uploaded_by`, `reencoded`);
5. generates the **responsive variants** of an image on a public disk (below; a failure never
   loses the upload: the file is marked for the optimiser).

Storing a file is not logged by the service: the module logs its own action (a CRUD write is
logged by the Core, a file-manager upload by the Files module).

## Dated folders (D-54-01)

Files a module stores go to `<folder>/YYYY/MM/` (upload date, the site's time zone), so no
folder ever holds tens of thousands of files: the module passes `modules/news`, the service
writes `modules/news/2026/11/<32 hex>.jpg`. Date folders get the disk's permissions.

- **Which folders:** only the module spaces (`Storage::isModuleFolder()`: `modules/<module>/…`).
  The Files module's user folders (personal `users/<id>/…` and shared `shared/…`) never get one:
  the folder the user chose is the folder. `store(…, dated: true|false)` overrides the rule.
- **Layout:** `Config\Storage::$datedFolders` (a `date()` format, default `'Y/m'`; only digits,
  `/` and `-` may come out of it); `null` turns dated folders off.
- **Finding a module's files:** `Storage::baseFolder($path)` gives the folder a file was stored
  into (`modules/news/2026/11/x.jpg` → `modules/news`) and `Storage::isFileOf($path, $folder)`
  tells whether a file belongs to a folder, directly or through its date subfolders. The file
  picker (Core and Files backends), the Files module's read-only module listing and its private
  file rule use them. Backups copy whole disks, so dated folders and variants are included.
  (A module folder whose own subfolders are only digits would be read as a date folder.)
- Existing files keep their paths (the path is in `stored_files`): nothing is moved, and
  `storage:optimize` never moves files either (URLs stored in rich text would break).

## Images: size limit and responsive variants (D-54-01)

**Resize on upload.** Every re-encoded image is scaled down to at most **1920 px** on its longest
side (setting `Core.imageMaxDimension`, Settings › Security › Uploads, minimum 320), keeping the
aspect ratio, after the JPEG orientation and before encoding (`imagecopyresampled`; PNG/WebP
alpha and the GIF transparent colour kept; never scaled up). **The original is not kept.** A
field may only narrow the limit: `UploadRules::make('jpg,png')->maxDimension(1600)`,
`PickerField::maxDimension(400)`, `CrudUploadHandler::MAX_DIMENSION` (News covers 1600, avatars
400); a larger value does not widen the global one. Fields with `reencode: false` are not resized
(keep them rare). Before decoding, `width × height × 5` bytes are compared with what is left of
`memory_limit`: a too-large image is refused with the reason `memory` instead of a fatal error.

**Variants.** For images on a **public** disk the service generates copies at the widths of
`Config\Storage::$imageVariantWidths` (default **400, 800, 1600**), only those narrower than the
stored image, in **WebP** (`$variantQuality`, default 80; the image's own format when GD has no
WebP, or when a PNG/GIF copy is smaller than the WebP one, as with flat logos and graphics). They are stored beside the file (`modules/news/2026/11/<hex>-w400.webp`) and recorded in
`stored_file_variants` (stored_file_id, width, height, format, path, size), deleted with the file
(`Storage::delete()`, so also the Files trash purge). They are generated **on upload and by the
optimiser only**: there is no resize endpoint and nothing a visitor can make the server compute.
Private files get none (the Files module keeps its own thumbnails). API:
`service('storage')->variants()` (`of($id)`, `prefetch($ids)`, `missing($file)`,
`generate($file)`, `delete($file)`).

**Printing a stored image: `ui_image()`.** Public views never write `<img src="<url>">` for a
stored image:

```php
<?= ui_image($post['cover'], [            // StoredFile, id, file key or a site URL of a public file
    'alt'   => $alt,                      // '' for a decorative image
    'class' => 'card-img-top',
    'sizes' => '(min-width: 992px) 33vw, 100vw', // default 100vw
    'lazy'  => true,                      // false for the image above the fold (no loading="lazy")
]) ?>
```

It prints `src` (the widest variant), `srcset` (every variant + the file), `sizes`, the stored
`width`/`height` (no layout shift: give such images `height: auto` in CSS), `alt`, `class`,
`loading="lazy"` and `decoding="async"`; a file without variants or an external URL gives a plain
`<img>`; everything is escaped. `ui_image_url($file, 1600)` gives the URL of the widest variant up
to a width (`og:image` uses 1600). Images inserted in **rich text** get the same `srcset`,
`sizes`, `width`/`height` and `loading` from `HtmlSanitizer` when their `src` is a stored public
image with variants (`src` keeps the file's URL); the purifier still refuses those attributes from
the client.

## Optimising stored images: `storage:optimize` and the weekly task

`Modules\Core\Storage\ImageOptimizer` finds images above `Core.imageMaxDimension` and public
images missing variants, and processes them: the image is resized **in place** (re-encoded,
metadata stripped, same key, path and URL; `stored_files` size, width, height and SHA-256
updated) and its variants are (re)generated. Files stored with `reencode: false`
(`stored_files.reencoded = 0`) are skipped. A file that fails is marked (`optimize_failed_at`,
`optimize_error`), listed, and skipped by the next runs (`--retry-failed` clears the marks).

- `php spark storage:optimize [--dry-run] [--disk=public|private] [--folder=<path>] [--limit=N]
  [--retry-failed]` prints one line per file and a summary (files, bytes before/after); resumable
  and idempotent; `--dry-run` writes nothing (the variant sizes are estimated). One activity-log
  entry `Core`/`images_optimized` per real run. **Run it once after updating to this version.**
- The Core's scheduled task **Optimise stored images** (`Modules\Core\Tasks\OptimizeImagesTask`,
  Sundays 03:17, enabled by default) does the same in bounded batches
  (`Config\Storage::$optimizeBatch` = 200 files or `$optimizeSeconds` = 240 s) and continues on the
  next run; its result shows in Settings › Scheduled tasks and once in the activity log.
- System information (`docs/system.md`) shows the stored bytes per disk and module folder, the
  variants, the images waiting (warning, stronger when the task is disabled or has not run for
  14 days) and the files the optimiser could not process.

## Unused files (D-55-01)

Content keeps only a file key, id or URL, the same file may be used in several places (the
picker offers files already uploaded, revisions keep old references), so deleting a post, a page
or a section never deletes its files. `Modules\Core\Storage\UnusedFiles` finds the stored files
of the **module spaces** (`modules/<module>/…`, both disks, dated subfolders included) that no
module uses any more.

**Who uses what.** Every module that stores files declares a
`Modules\Core\Storage\FileReferenceProvider` in its manifest's `$fileReferences`:

```php
final class NewsFileReferences implements FileReferenceProvider
{
    public function folders(): array
    {
        return ['modules/news'];            // the folders it answers for
    }

    public function references(): iterable // everything that can bring a file back
    {
        yield from TableReferences::texts('news', ['cover']);
        yield from TableReferences::texts('news_translations', ['summary', 'body']);
    }
}
```

`references()` yields stored-file ids (int), file keys, site URLs or any stored text (HTML, JSON):
the Core finds in them every 32-hex token (keys and the random names of stored files and their
variants) and every path under a public disk's URL (`RichTextFiles::references()`), so an image
inside rich text, a key inside section JSON or a revision snapshot is found without parsing it.
`TableReferences::texts()` / `ids()` read whole tables in batches, **rows in the trash included**
(they can be restored). Include current content in every language, the trash, revisions, drafts,
scheduled content and settings; giving too much only keeps a file, missing one loses it. A
provider may answer for no folder and still protect files it links to (Events, Assistant, Menus).

| Provider | Folders | References |
|---|---|---|
| Core `SettingsFileReferences` | — | every stored setting value and theme option (logo, images in translated settings) |
| News `NewsFileReferences` | `modules/news` | covers, category banners, post and category texts (every language, trash included) |
| Pages `PageFileReferences` | `modules/pages` | featured images, bodies, section data and options of pages and reusable blocks (hidden sections too), page and block **revisions** |
| Events `EventFileReferences` | — | descriptions, event URLs |
| Assistant `FaqFileReferences` | — | FAQ answers and links |
| Tutorial `AttachmentReferences` | `modules/tutorial` | task attachments, descriptions |
| Menus `MenuFileReferences` | — | menu item targets, back-office links |
| Files `FileManagerReferences` | — | every `files` row (its trash purge deletes them) |

**Safety rules** (each has a test):

- only files inside a folder some **enabled** module answers for are candidates; any other module
  folder is reported as **not checked** (count and size) and never listed or deleted, nor are the
  folders of a disabled module; the references of disabled modules still count;
- `users/…` and `shared/…` are never candidates (the Files module and its trash own them);
- a file younger than the grace period (`Core.unusedFilesGraceDays`, default 30, minimum 7) is
  never a candidate (it may sit in a form not saved yet);
- if any provider throws, is missing or declares a folder outside the module spaces, the whole
  scan fails: nothing is listed or deleted;
- a reference to a file that no longer exists is ignored;
- deleting collects the references again and re-checks every file just before deleting it (a
  reference added after the scan saves it); it goes through `Storage::delete()` (file, variants,
  row) and writes **one** activity entry per run (`Core`/`unused_files_deleted`: count, bytes,
  skipped per reason, via `screen`/`command`/`task`, the first 100 paths);
- the scan reads `stored_files` in batches and is cached for an hour (System information stays
  fast; "Scan now" and every deletion drop the cache).

**Backups are not changed:** deleted files stay in earlier backups; make a backup first
([`backup-restore.md`](backup-restore.md)).

Where to find them:

- `php spark storage:unused [--module=<Name>] [--older-than=<days>] [--delete] [--yes] [--limit=N]`
  ([`commands.md`](commands.md));
- Settings › System › **Unused files** ([`system.md`](system.md));
- the weekly task **Delete unused files** (`DeleteUnusedFilesTask`, Sundays 04:23), **off by
  default**: it does nothing until `Core.unusedFilesAutoDelete` is turned on in Settings ›
  Security › Uploads (re-authentication, logged as a security settings change); then it deletes
  unused files older than `Core.unusedFilesAutoDeleteDays` (default 90, never below the grace
  period) in batches (`Config\Storage::$unusedDeleteBatch` = 500 files or `$unusedDeleteSeconds` =
  240 s per run), with the same rules; a failing provider fails the run.

The end-to-end tests of a demo site may lower the grace period with
`storage.unusedFilesTestGraceDays` in `.env`; it is ignored in production.

## Upload rules

Global rules (settings, edited in Settings › Security; forbidden extensions are refused there too):

| Setting | Default | Meaning |
|---|---|---|
| `Core.uploadTypes` | `jpg,jpeg,png,gif,webp,pdf,txt,csv,odt,ods,odp,doc,docx,xls,xlsx,ppt,pptx` | extensions allowed anywhere |
| `Core.uploadMaxSize` | `10` | MB (PHP's `upload_max_filesize` and `post_max_size` must allow it) |
| `Core.uploadAllowSvg` | off | SVG accepted (sanitised) by fields that list `svg` |
| `Core.imageMaxDimension` | `1920` | longest side of stored images in px (minimum 320; D-54-01) |

A field's `UploadRules` can only **narrow** them: its extensions must also be in the global list
and the smaller size wins. `reencode: false` keeps the original bytes of an image (only for a
field that needs them).

Checks of every upload:

- **Extension** of the client's name, lower case, in both lists. Never accepted, whatever the
  settings say: `Config\Storage::$forbiddenExtensions` (PHP and other server code, HTML, XML,
  JavaScript, executables, `.htaccess`, `.env`…), and a name without extension.
- **MIME type** detected from the contents (`finfo`, never the browser's) must belong to the
  extension (`Config\Mimes` + `Config\Storage::$extraMimes`). The file is stored and served with
  the canonical type of the extension. HTML, XML, SVG or PHP contents under another extension and
  `<?php` in the first 64 KB are refused.
- **Size**: not empty and within the limit.
- **Images** (`jpg`, `jpeg`, `png`, `gif`, `webp`) must decode and match their extension, at most
  40 megapixels (`Config\Storage::$maxImagePixels`), and are **re-encoded** with GD by default:
  EXIF/XMP/IPTC metadata (GPS position…) and anything hidden or appended are dropped; JPEG
  orientation is applied first; animated GIFs keep their first frame; then they are scaled down
  to `Core.imageMaxDimension` (above). An image too large for the memory left is refused
  (`memory`).
- **SVG** only with `Core.uploadAllowSvg` and a field that lists it: DOCTYPEs are refused and
  `SvgSanitizer` keeps only drawing elements and presentation attributes (no scripts, event
  handlers, `<style>`, `style`, `foreignObject`, links, external or `data:`/`javascript:`
  references). Private SVGs are always served as downloads.

## Serving private files

`GET files/private/{key}` (`Modules\Core\Controllers\PrivateFiles`) is the only way to read a file
of the private disk:

- the access rule (`Config\Storage::$accessRule`, interface `FileAccessRule`) decides. The Core's
  default is `OwnerAccess` (the user who uploaded the file and superadmins); the Files module
  replaces it through its registrar with `Modules\Files\Services\FolderAccessRule`: the `view`
  right of the file's folder (folder ACL), a module's `FileAccessPolicy` for its folder, and
  `OwnerAccess` for files outside every managed folder or while Files is disabled
  (`docs/files.md`);
- anything not allowed, an unknown or malformed key, a public-disk file or a file missing on disk
  is a **404** (the existence of a file is not revealed);
- headers: the stored `Content-Type`, `X-Content-Type-Options: nosniff`,
  `Content-Disposition: inline` only for raster images and PDF (otherwise, and with
  `?download=1`, `attachment`) with the original name, `Content-Security-Policy: default-src
  'none'; …; sandbox` (PDF without `sandbox`), `X-Frame-Options: SAMEORIGIN`,
  `Cross-Origin-Resource-Policy: same-origin` and `Cache-Control: private, no-store`, plus the
  global security headers.

Application access control never relies on file permissions: a private file is only read through
this controller. The web server must never serve `writable/` (see `docs/web-server.md`).

## Tests

`modules/Core/Tests/UploadRulesTest.php` (traversal, forbidden types, SVG refused and sanitised,
MIME checks, sizes, EXIF stripped), `StorageTest.php` (random names, permissions `0750`/`0640`,
refusals logged, settings) and `PrivateFilesTest.php` (owner, other users, guests, superadmins,
headers, 404s). `StorageFixturesTrait` creates disks in `writable/tests/` and small test files.
`ImageOptimisationTest.php` (session 54): dated folders vs user folders, resizing (orientation,
transparency, no upscaling, field limits, `reencode: false`, memory guard), variants created and
deleted with the file, no on-demand route, `ui_image()` and rich-text `srcset`, `storage:optimize`
(dry run, idempotent, failures remembered), the weekly task in batches and the System information
rows.
