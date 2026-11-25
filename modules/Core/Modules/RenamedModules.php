<?php

declare(strict_types=1);

namespace Modules\Core\Modules;

/**
 * Bundled modules that a later release ships under another name (D-52-01). An installation that
 * still has the old name in its registry after updating finds its folder gone: `modules:sync`
 * (also run by `app:update`) and the Modules screen then say which step to follow instead of only
 * "its folder no longer exists". Data is never migrated automatically.
 *
 * Add an entry only when a bundled module is renamed; keep old entries (an installation may skip
 * releases).
 */
final class RenamedModules
{
    /** Old name => new name. */
    public const MAP = [
        'Tasks' => 'Tutorial', // session 52: the tutorial module
    ];

    /**
     * The new name of a bundled module renamed in a later release, or null.
     */
    public static function newName(string $module): ?string
    {
        return self::MAP[$module] ?? null;
    }

    /**
     * The step to follow for a registered module whose folder is gone, or null when it was not
     * renamed (translated: `Core.sync.renamed`).
     */
    public static function hint(string $module): ?string
    {
        $new = self::newName($module);

        return $new === null ? null : lang('Core.sync.renamed', [$module, $new]);
    }
}
