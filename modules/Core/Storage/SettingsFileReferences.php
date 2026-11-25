<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

/**
 * The files the Core's own data uses (D-55-01): every stored setting value of every module and
 * every theme option (a logo, a favicon, an image chosen in a setting, an image inside a
 * translated rich-text setting). The Core has no module folder of its own; page models store
 * their images in the Pages folder, where the Pages provider finds them in the sections.
 */
final class SettingsFileReferences implements FileReferenceProvider
{
    public function folders(): array
    {
        return [];
    }

    public function references(): iterable
    {
        yield from TableReferences::texts('settings', ['value']);
    }
}
