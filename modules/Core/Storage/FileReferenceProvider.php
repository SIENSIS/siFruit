<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

/**
 * The files a module uses (D-55-01, docs/storage.md "Unused files"): declared in the manifest's
 * `$fileReferences` and read by `UnusedFiles` (`php spark storage:unused`, Settings › System ›
 * Unused files, the automatic deletion task).
 *
 * - `folders()`: the module folders it answers for (`modules/news`): their stored files that no
 *   provider references may be listed as unused. A folder no enabled provider answers for is
 *   "not checked" and never touched. A provider may answer for no folder and still protect the
 *   files it references (e.g. links to files of other modules).
 * - `references()`: **everything that can bring a file back**: current content in every
 *   language, the trash, revisions, drafts, scheduled content, settings and images inside rich
 *   text. Each item is a stored-file id (int), a file key (32 hex), a site URL or path of a public
 *   file, or any stored text (HTML, JSON) to scan: `RichTextFiles::references()` and
 *   `TableReferences::texts()` help. Giving too much is safe; missing one loses a file.
 *
 * A provider that throws aborts the whole scan: nothing is listed or deleted.
 */
interface FileReferenceProvider
{
    /**
     * @return list<string> Module folders (`modules/<module>`, without date subfolders)
     */
    public function folders(): array;

    /**
     * @return iterable<int|string> Stored-file ids, file keys, site URLs or texts to scan
     */
    public function references(): iterable;
}
