<?php

declare(strict_types=1);

namespace Modules\Core\Security;

use HTMLPurifier_AttrTransform;

/**
 * After the attributes of `<i>` are validated (D-49-01): an icon (`class="bi bi-<name>"`) is
 * always `aria-hidden="true"` (icons in rich text are decorative), and `aria-hidden` is removed
 * from an `<i>` that is not an icon, so text in italics is never hidden from screen readers.
 */
final class IconAttributes extends HTMLPurifier_AttrTransform
{
    /**
     * @param array<string, string> $attr
     * @param \HTMLPurifier_Config  $config
     * @param \HTMLPurifier_Context $context
     *
     * @return array<string, string>
     */
    public function transform($attr, $config, $context): array
    {
        if (isset($attr['class'])) {
            $attr['aria-hidden'] = 'true';
        } else {
            unset($attr['aria-hidden']);
        }

        return $attr;
    }
}
