<?php

declare(strict_types=1);

namespace Modules\Pages\Storage;

use Modules\Core\Storage\FileReferenceProvider;
use Modules\Core\Storage\TableReferences;

/**
 * The files Pages uses (folder `modules/pages`): featured images, bodies and summaries in every
 * language, the data and options of every section of pages and reusable blocks (images,
 * backgrounds, rich-text fields; hidden sections too) and the snapshots of page and block
 * **revisions**, so an image an editor removed stays while a revision can bring it back. Page
 * models store their images here too (as sections of the pages made from them).
 *
 * Declared in the manifest's `$fileReferences` (D-55-01, docs/storage.md "Unused files"); the
 * Core scans the texts for file keys, stored names and public URLs (`RichTextFiles`). Rows in
 * the trash count: they can be restored.
 */
final class PageFileReferences implements FileReferenceProvider
{
    public function folders(): array
    {
        return ['modules/pages'];
    }

    public function references(): iterable
    {
        yield from TableReferences::texts('pages', ['featured_image']);
        yield from TableReferences::texts('page_translations', ['summary', 'body', 'canonical']);
        yield from TableReferences::texts('page_sections', ['options', 'data']);
        yield from TableReferences::texts('page_section_translations', ['data']);
        yield from TableReferences::texts('page_block_sections', ['options', 'data']);
        yield from TableReferences::texts('page_block_section_translations', ['data']);
        yield from TableReferences::texts('page_revisions', ['changes', 'snapshot']);
        yield from TableReferences::texts('page_block_revisions', ['changes', 'snapshot']);
    }
}
