<?php

declare(strict_types=1);

namespace Modules\Assistant\Settings;

use Modules\Assistant\Search\FaqSearch;
use Modules\Core\Search\SearchIndex;
use Modules\Core\Settings\SettingOptionsProvider;

/**
 * The choices of `Assistant.sources`: the site search sources of the enabled modules (their
 * labels), except the FAQs, which the assistant always uses first.
 */
final class SourceOptions implements SettingOptionsProvider
{
    public function options(): array
    {
        $labels = SearchIndex::labels();
        unset($labels[FaqSearch::SOURCE]);

        return $labels;
    }
}
