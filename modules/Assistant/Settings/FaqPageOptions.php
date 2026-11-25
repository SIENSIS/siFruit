<?php

declare(strict_types=1);

namespace Modules\Assistant\Settings;

use Modules\Assistant\Services\FaqPage;
use Modules\Core\Settings\SettingOptionsProvider;

/**
 * Choices of `Assistant.faqPage` (D-51-03): "none" (the module's own page `faq`) and the site's
 * pages (tree order) through the link type `pages.page`.
 */
final class FaqPageOptions implements SettingOptionsProvider
{
    public function options(): array
    {
        $options = ['' => lang('Assistant.settings.faqPageNone')];

        foreach ((new FaqPage())->options() as $id => $title) {
            $options[(string) $id] = $title;
        }

        return $options;
    }
}
