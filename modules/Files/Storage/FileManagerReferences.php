<?php

declare(strict_types=1);

namespace Modules\Files\Storage;

use Modules\Core\Storage\FileReferenceProvider;
use Modules\Core\Storage\TableReferences;

/**
 * The files the file manager owns: every `files` row (its trash included; the trash purge deletes
 * them) and the thumbnails' names. They are never unused here. Its folder `modules/files` holds
 * only the thumbnails, which are not stored files.
 *
 * Declared in the manifest's `$fileReferences` (D-55-01, docs/storage.md "Unused files"); the
 * Core scans the texts for file keys, stored names and public URLs (`RichTextFiles`). Rows in
 * the trash count: they can be restored.
 */
final class FileManagerReferences implements FileReferenceProvider
{
    public function folders(): array
    {
        return [];
    }

    public function references(): iterable
    {
        yield from TableReferences::ids('files', 'stored_file_id');
        yield from TableReferences::texts('files', ['thumbnail']);
    }
}
