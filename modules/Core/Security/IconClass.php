<?php

declare(strict_types=1);

namespace Modules\Core\Security;

use HTMLPurifier_AttrDef;
use Modules\Core\Frontend\Icons;

/**
 * The `class` attribute of `<i>` in rich text (D-49-01): exactly `bi` plus one `bi-<name>` whose
 * name is in the vendored Bootstrap Icons list (Modules\Core\Frontend\Icons). Anything else (an
 * unknown name, another class, a second icon) removes the attribute; the value is rewritten as
 * `bi bi-<name>`.
 */
final class IconClass extends HTMLPurifier_AttrDef
{
    /**
     * @param string                $string
     * @param \HTMLPurifier_Config  $config
     * @param \HTMLPurifier_Context $context
     *
     * @return false|string
     */
    public function validate($string, $config, $context)
    {
        return self::normalise((string) $string) ?? false;
    }

    /**
     * `bi bi-<name>` for a valid value, null otherwise.
     */
    public static function normalise(string $value): ?string
    {
        $classes = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        if ($classes === false || count($classes) !== 2 || ! in_array('bi', $classes, true)) {
            return null;
        }

        $icon = $classes[0] === 'bi' ? $classes[1] : $classes[0];

        return Icons::exists($icon) ? 'bi ' . $icon : null;
    }
}
