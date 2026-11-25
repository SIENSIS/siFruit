<?php

declare(strict_types=1);

namespace Modules\Events\Storage;

use Modules\Core\Storage\FileReferenceProvider;
use Modules\Core\Storage\TableReferences;

/**
 * The files Events links to: images and links in event and category descriptions (every
 * language) and the events' URLs. Events has no folder of its own (its editor has no image
 * upload): it only keeps the files of other folders it uses.
 *
 * Declared in the manifest's `$fileReferences` (D-55-01, docs/storage.md "Unused files"); the
 * Core scans the texts for file keys, stored names and public URLs (`RichTextFiles`). Rows in
 * the trash count: they can be restored.
 */
final class EventFileReferences implements FileReferenceProvider
{
    public function folders(): array
    {
        return [];
    }

    public function references(): iterable
    {
        yield from TableReferences::texts('events', ['url', 'source_url']);
        yield from TableReferences::texts('event_translations', ['description']);
        yield from TableReferences::texts('event_category_translations', ['description']);
    }
}
