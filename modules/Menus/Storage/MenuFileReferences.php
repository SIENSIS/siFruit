<?php

declare(strict_types=1);

namespace Modules\Menus\Storage;

use Modules\Core\Storage\FileReferenceProvider;
use Modules\Core\Storage\TableReferences;

/**
 * The files menus link to (a PDF in a menu item, a custom back-office link): the targets of
 * public menu items and back-office links. Menus has no folder of its own.
 *
 * Declared in the manifest's `$fileReferences` (D-55-01, docs/storage.md "Unused files"); the
 * Core scans the texts for file keys, stored names and public URLs (`RichTextFiles`). Rows in
 * the trash count: they can be restored.
 */
final class MenuFileReferences implements FileReferenceProvider
{
    public function folders(): array
    {
        return [];
    }

    public function references(): iterable
    {
        yield from TableReferences::texts('menu_items', ['value']);
        yield from TableReferences::texts('backend_menu_links', ['value']);
    }
}
