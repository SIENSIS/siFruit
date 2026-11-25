<?php

declare(strict_types=1);

namespace Modules\Assistant\Storage;

use Modules\Core\Storage\FileReferenceProvider;
use Modules\Core\Storage\TableReferences;

/**
 * The files the assistant's FAQs link to: images and links in the answers (every language) and
 * the FAQ links. The Assistant has no folder of its own: it only keeps the files of other
 * folders it uses.
 *
 * Declared in the manifest's `$fileReferences` (D-55-01, docs/storage.md "Unused files"); the
 * Core scans the texts for file keys, stored names and public URLs (`RichTextFiles`). Rows in
 * the trash count: they can be restored.
 */
final class FaqFileReferences implements FileReferenceProvider
{
    public function folders(): array
    {
        return [];
    }

    public function references(): iterable
    {
        yield from TableReferences::texts('assistant_faqs', ['link']);
        yield from TableReferences::texts('assistant_faq_translations', ['answer', 'link_label']);
    }
}
