<?php

declare(strict_types=1);

namespace Modules\News\Storage;

use Modules\Core\Storage\FileReferenceProvider;
use Modules\Core\Storage\TableReferences;

/**
 * The files News uses: post covers, category banners and the images and links in post and
 * category texts, in every language (folder `modules/news`).
 *
 * Declared in the manifest's `$fileReferences` (D-55-01, docs/storage.md "Unused files"); the
 * Core scans the texts for file keys, stored names and public URLs (`RichTextFiles`). Rows in
 * the trash count: they can be restored.
 */
final class NewsFileReferences implements FileReferenceProvider
{
    public function folders(): array
    {
        return ['modules/news'];
    }

    public function references(): iterable
    {
        yield from TableReferences::texts('news', ['cover']);
        yield from TableReferences::texts('news_translations', ['summary', 'body']);
        yield from TableReferences::texts('news_categories', ['banner']);
        yield from TableReferences::texts('news_category_translations', ['description']);
    }
}
