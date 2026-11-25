<?php

declare(strict_types=1);

/**
 * Events of the Assistant module (discovered by CodeIgniter, also while the module is disabled, so
 * the listener checks it):
 *
 * - `themeFloating` (the Core's public layout, `theme_floating()`, D-51-03): the chat bubble, when
 *   the module is enabled and `Assistant.enabled` is on.
 */

use CodeIgniter\Events\Events;
use Modules\Assistant\Services\Bubble;
use Modules\Core\Registry\ModuleState;
use Modules\Core\Theme\ZoneContent;

Events::on('themeFloating', static function (ZoneContent $content): void {
    if ((new ModuleState())->isEnabled('Assistant') && setting('Assistant.enabled')) {
        $content->add((new Bubble())->render());
    }
});
