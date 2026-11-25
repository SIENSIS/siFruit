<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Files\File;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\I18n\Time;
use CodeIgniter\Shield\Entities\User;
use Config\Storage as StorageConfig;
use Throwable;

/**
 * The Storage service (`service('storage')`, docs/storage.md): the `public` and `private` disks
 * and the only way to store uploads. `store()` checks the upload rules (UploadValidator),
 * re-encodes images, gives the file a random name inside the folder, writes it with the disk's
 * permissions and records it in `stored_files`; refused uploads throw UploadRefused and are
 * written to the security log (`upload_refused`).
 *
 * ```php
 * $file = service('storage')->store($this->request->getFile('attachment'), 'private', 'modules/news',
 *     UploadRules::make('pdf,jpg,png', 5 * 1024 * 1024));
 * $url  = service('storage')->url($file); // files/private/{key} (checked) or the public URL
 * ```
 */
final class Storage
{
    /** @var array<string, Disk> */
    private array $disks = [];

    private ?UploadValidator $validator;

    private ?ImageVariants $variants = null;

    public function __construct(private readonly StorageConfig $config, ?UploadValidator $validator = null)
    {
        $this->validator = $validator;
    }

    public function config(): StorageConfig
    {
        return $this->config;
    }

    /**
     * @throws StorageException for an unknown disk
     */
    public function disk(string $name): Disk
    {
        if (! isset($this->config->disks[$name])) {
            throw StorageException::unknownDisk($name);
        }

        return $this->disks[$name] ??= new Disk($name, $this->config->disks[$name]);
    }

    /**
     * The responsive copies of public images (D-54-01).
     */
    public function variants(): ImageVariants
    {
        return $this->variants ??= new ImageVariants($this);
    }

    public function validator(): UploadValidator
    {
        return $this->validator ??= UploadValidator::fromSettings($this->config);
    }

    /**
     * Stores an uploaded file (or a file already on the server, with its original name).
     *
     * @param string      $disk   `public` or `private`
     * @param string      $folder folder inside the disk (e.g. `modules/news`, `users/7`)
     * @param string|null $name   original name of a File (UploadedFile: the client's name)
     * @param bool|null   $dated  add the date subfolder (`Config\Storage::$datedFolders`); null =
     *                            only for the module spaces (`modules/…`), never for user folders
     *
     * @throws UploadRefused    when the file breaks a rule (already logged)
     * @throws StorageException for an unknown disk or an invalid folder
     */
    public function store(UploadedFile|File $upload, string $disk, string $folder, ?UploadRules $rules = null, ?string $name = null, ?bool $dated = null): StoredFile
    {
        $target = $this->disk($disk);
        $folder = PathNormalizer::normalize($folder, true);
        $rules ??= new UploadRules();
        $base   = $folder;

        $original = $upload instanceof UploadedFile ? $upload->getClientName() : ($name ?? $upload->getBasename());
        $original = self::cleanName($original);

        try {
            if ($upload instanceof UploadedFile && (! $upload->isValid() || $upload->hasMoved())) {
                throw new UploadRefused($upload->getError() === UPLOAD_ERR_INI_SIZE || $upload->getError() === UPLOAD_ERR_FORM_SIZE ? 'too_large' : 'invalid', [
                    (string) round($this->validator()->maxSize($rules) / 1048576, 1),
                ]);
            }

            $valid = $this->validator()->validate($upload->getRealPath() ?: $upload->getPathname(), $original, $rules);
        } catch (UploadRefused $refused) {
            $this->logRefusal($refused, $original, $disk, $folder);

            throw $refused;
        }

        $folder = $this->datedFolder($base, $dated ?? self::isModuleFolder($base));

        return $this->write($target, $folder, $valid, $upload->getRealPath() ?: $upload->getPathname(), $original);
    }

    /**
     * The folder with its date subfolder (`modules/news` → `modules/news/2026/11`), or the folder
     * itself when dated folders are off or not wanted.
     */
    public function datedFolder(string $folder, bool $dated = true): string
    {
        $format = $this->config->datedFolders;

        if (! $dated || $format === null || $format === '') {
            return $folder;
        }

        $suffix = trim(Time::now()->format($format), '/');

        if ($suffix === '' || preg_match('#^[0-9][0-9/-]*$#', $suffix) !== 1) {
            return $folder; // a format giving anything but digits is ignored
        }

        return PathNormalizer::join($folder, $suffix);
    }

    /**
     * Whether a folder belongs to a module space (`modules/<module>/…`), whose files get dated
     * subfolders.
     */
    public static function isModuleFolder(string $folder): bool
    {
        return preg_match('#^modules/[^/]+#', $folder) === 1;
    }

    /**
     * The folder a file was stored into, without its date subfolder: `modules/news/2026/11/x.jpg`
     * → `modules/news`. Files outside the module spaces: the folder of the path.
     */
    public static function baseFolder(string $path): string
    {
        $slash  = strrpos($path, '/');
        $folder = $slash === false ? '' : substr($path, 0, $slash);

        if (! self::isModuleFolder($folder)) {
            return $folder;
        }

        while (preg_match('#^(modules/.+)/[0-9][0-9-]*$#', $folder, $match) === 1) {
            $folder = $match[1];
        }

        return $folder;
    }

    /**
     * Whether a stored path is a file of a folder, directly or in its date subfolders.
     */
    public static function isFileOf(string $path, string $folder): bool
    {
        return self::baseFolder($path) === $folder;
    }

    /**
     * Stores contents generated by the application (exports, generated images…) through the same
     * rules as an upload.
     *
     * @throws UploadRefused
     * @throws StorageException
     */
    public function storeContents(string $contents, string $originalName, string $disk, string $folder, ?UploadRules $rules = null, ?bool $dated = null): StoredFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'kps');

        if ($temp === false) {
            throw new UploadRefused('invalid');
        }

        try {
            file_put_contents($temp, $contents);

            return $this->store(new File($temp), $disk, $folder, $rules, $originalName, $dated);
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    public function find(string $key): ?StoredFile
    {
        if (preg_match('/^[a-f0-9]{32}$/', $key) !== 1) {
            return null;
        }

        $row = $this->db()->table('stored_files')->where('file_key', $key)->get()->getRowArray();

        return is_array($row) ? StoredFile::fromRow($row) : null;
    }

    public function findById(int $id): ?StoredFile
    {
        $row = $this->db()->table('stored_files')->where('id', $id)->get()->getRowArray();

        return is_array($row) ? StoredFile::fromRow($row) : null;
    }

    /**
     * Deletes the file, its variants and its record.
     */
    public function delete(StoredFile $file): void
    {
        $disk = $this->disk($file->disk);
        $this->variants()->delete($file);

        if ($disk->exists($file->path)) {
            $disk->delete($file->path);
        }

        $this->db()->table('stored_files')->where('id', $file->id)->delete();
    }

    /**
     * The URL of a file: the public URL on a public disk, `files/private/{key}` (checked by
     * Config\Storage::$accessRule) on a private one.
     */
    public function url(StoredFile $file, bool $download = false): string
    {
        $disk = $this->disk($file->disk);

        if ($disk->isPublic()) {
            return $disk->url($file->path);
        }

        return site_url('files/private/' . $file->key) . ($download ? '?download=1' : '');
    }

    /**
     * Whether a user (null: a guest) may read a private file (Config\Storage::$accessRule).
     */
    public function canView(?User $user, StoredFile $file): bool
    {
        $class = $this->config->accessRule;

        // A misconfigured rule (e.g. from .env) denies everything.
        if (! is_a($class, FileAccessRule::class, true)) {
            return false;
        }

        return (new $class())->canView($user, $file);
    }

    /**
     * The original name as metadata: no folders, no control characters, at most 255 bytes.
     */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name));

        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        return $name === '' ? 'file' : mb_strcut($name, 0, 255, 'UTF-8');
    }

    private function write(Disk $disk, string $folder, ValidatedUpload $valid, string $source, string $original): StoredFile
    {
        $path = PathNormalizer::join($folder, bin2hex(random_bytes(16)) . '.' . $valid->extension);

        if ($valid->contents !== null) {
            $disk->write($path, $valid->contents);
            $size     = strlen($valid->contents);
            $checksum = hash('sha256', $valid->contents);
        } else {
            $stream = fopen($source, 'rb');

            if ($stream === false) {
                throw new UploadRefused('invalid');
            }

            try {
                $disk->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }

            $size     = (int) filesize($source);
            $checksum = (string) hash_file('sha256', $source);
        }

        $row = [
            'file_key'      => bin2hex(random_bytes(16)),
            'disk'          => $disk->name(),
            'path'          => $path,
            'original_name' => $original,
            'extension'     => $valid->extension,
            'mime'          => $valid->mime,
            'size'          => $size,
            'checksum'      => $checksum,
            'width'         => $valid->width,
            'height'        => $valid->height,
            'uploaded_by'   => self::currentUserId(),
            'created_at'    => Time::now()->toDateTimeString(),
            // An image stored as uploaded (`reencode: false`) is never resized by the optimiser.
            'reencoded'     => $valid->isImage() && $valid->contents === null ? 0 : 1,
        ];

        try {
            $this->db()->table('stored_files')->insert($row);
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        $stored = StoredFile::fromRow(['id' => (int) $this->db()->insertID()] + $row);

        // Responsive copies of public images; a failure never loses the upload (the optimiser
        // retries it once and reports it).
        try {
            $this->variants()->generate($stored);
        } catch (Throwable $e) {
            $this->markFailed($stored, $e->getMessage());
        }

        return $stored;
    }

    /**
     * Remembers that the optimiser (or the variants on upload) could not process a file: the next
     * runs skip it and System information lists it.
     */
    public function markFailed(StoredFile $file, string $error): void
    {
        try {
            $this->db()->table('stored_files')->where('id', $file->id)->update([
                'optimize_failed_at' => Time::now()->toDateTimeString(),
                'optimize_error'     => mb_strcut($error, 0, 255, 'UTF-8'),
            ]);
        } catch (Throwable) {
            // reporting only
        }
    }

    private function logRefusal(UploadRefused $refused, string $original, string $disk, string $folder): void
    {
        try {
            security_log('Core', 'upload_refused', [
                'summary' => $original,
                'after'   => ['reason' => $refused->reason(), 'disk' => $disk, 'folder' => $folder],
            ]);
        } catch (Throwable) {
            // the log must never hide the refusal
        }
    }

    private static function currentUserId(): ?int
    {
        try {
            $id = auth()->id();
        } catch (Throwable) {
            return null;
        }

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @return BaseConnection<object|resource, object|resource>
     */
    private function db(): BaseConnection
    {
        return db_connect();
    }
}
