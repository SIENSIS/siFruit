<?php

declare(strict_types=1);

namespace Modules\Settings\Forms;

use Closure;
use CodeIgniter\HTTP\Files\UploadedFile;
use Modules\Core\Storage\StoredFile;
use Modules\Core\Storage\UploadRefused;
use Modules\Core\Storage\UploadRules;
use Throwable;

/**
 * The `image` fields of a settings form (a logo, the favicon, the app icon…): an uploaded file
 * (`<field>-file`) is stored through the Storage service on the public disk, in `settings/<folder>`
 * (allow-listed image types, random name, re-encoded), and the setting keeps its path under
 * `public/` (`uploads/settings/…`, relative so it survives a move to another host). A file that
 * a new upload or "use the default" replaces is deleted once no stored setting uses it any more;
 * files outside `uploads/settings/` (a theme's own images) are never touched.
 */
final class ImageUploads
{
    /** Image types accepted (SVG only while `Core.uploadAllowSvg` is on: the Storage rules). */
    public const TYPES = 'png,jpg,jpeg,gif,webp,svg';

    private const PREFIX = 'uploads/settings/';

    /** @var list<StoredFile> files stored by apply() */
    private array $stored = [];

    /**
     * Stores the uploaded files of the editable image fields.
     *
     * @param list<Field>                           $fields
     * @param Closure(string): (UploadedFile|null) $file the uploaded file of an input name
     *
     * @return array{values: array<string, string>, errors: array<string, string>} values: setting => path
     */
    public function apply(array $fields, Closure $file, string $folder): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            if ($field->type !== 'image' || $field->readOnly) {
                continue;
            }

            $upload = $file($field->name . '-file');

            if (! $upload instanceof UploadedFile || $upload->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            try {
                $stored         = service('storage')->store($upload, 'public', 'settings/' . $folder, UploadRules::make(self::TYPES), dated: false);
                $this->stored[] = $stored;
                $values[$field->setting] = self::path($stored);
            } catch (UploadRefused $refused) {
                $errors[$field->name] = $refused->getMessage();
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Deletes the files stored by apply() (the form was refused after all).
     */
    public function discard(): void
    {
        foreach ($this->stored as $file) {
            service('storage')->delete($file);
        }

        $this->stored = [];
    }

    /**
     * Deletes the uploaded files the saved values replaced, when no stored setting uses them.
     *
     * @param list<Field>          $fields
     * @param array<string, mixed> $saved  setting => value just saved
     */
    public function forgetReplaced(array $fields, array $saved): void
    {
        foreach ($fields as $field) {
            $old = is_string($field->value) ? $field->value : '';

            if ($field->type !== 'image' || ! array_key_exists($field->setting, $saved) || $saved[$field->setting] === $old || ! str_starts_with($old, self::PREFIX)) {
                continue;
            }

            $db = db_connect();

            if ($db->table('settings')->like('value', $old, 'both', null, true)->countAllResults() > 0) {
                continue; // still used by another setting (another theme, another option)
            }

            $row = $db->table('stored_files')->where('disk', 'public')->where('path', substr($old, strlen('uploads/')))->get()->getRowArray();

            if (is_array($row)) {
                try {
                    service('storage')->delete(StoredFile::fromRow($row));
                } catch (Throwable) {
                    // A file that cannot be deleted stays; the unused-files scan lists it.
                }
            }
        }
    }

    /** The path of a stored public file under `public/` (`uploads/settings/…`). */
    public static function path(StoredFile $file): string
    {
        return 'uploads/' . $file->path;
    }
}
