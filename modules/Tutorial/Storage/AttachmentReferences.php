<?php

declare(strict_types=1);

namespace Modules\Tutorial\Storage;

use Modules\Core\Storage\FileReferenceProvider;
use Modules\Core\Storage\TableReferences;

/**
 * The files the Tutorial module uses (folder `modules/tutorial`): task attachments (the
 * attachments table and the task column) and links in project and task descriptions.
 *
 * Declared in the manifest's `$fileReferences` (D-55-01, docs/storage.md "Unused files"); the
 * Core scans the texts for file keys, stored names and public URLs (`RichTextFiles`). Rows in
 * the trash count: they can be restored.
 */
final class AttachmentReferences implements FileReferenceProvider
{
    public function folders(): array
    {
        return [AttachmentUploads::FOLDER];
    }

    public function references(): iterable
    {
        yield from TableReferences::texts('tutorial_task_attachments', ['file_key']);
        yield from TableReferences::texts('tutorial_tasks', ['attachments', 'description']);
        yield from TableReferences::texts('tutorial_project_translations', ['description']);
    }
}
