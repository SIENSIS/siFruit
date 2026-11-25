<?php

declare(strict_types=1);

namespace Modules\Core\Security;

use HTMLPurifier;
use HTMLPurifier_AttrDef_Enum;
use HTMLPurifier_Config;
use Modules\Core\Storage\ResponsiveImage;
use Throwable;

/**
 * Sanitises rich text (news bodies, pages, event descriptions…) with HTMLPurifier before it is
 * stored and again when it is shown (specification "Security baseline": rich text through
 * HTMLPurifier). Allowed: paragraphs, headings, lists,
 * emphasis, links (`http`, `https`, `mailto`; external ones open with `rel="noopener noreferrer"`),
 * images of the site itself only (relative URLs or the site's hosts: SiteImagesOnly, D-17-08), quotes,
 * code, tables. Scripts, event handlers, styles, iframes, forms and
 * `javascript:`/`data:` URLs are removed.
 *
 * Alignment (`style="text-align: …"`, `align`, the editor's `ql-align-*`) is kept as one of the
 * classes `text-center`, `text-right`, `text-justify` on paragraphs, headings, list items, quotes
 * and table cells (AlignmentToClass); no `style` attribute survives.
 *
 * Icons (D-49-01): `<i class="bi bi-<name>" aria-hidden="true"></i>` is kept when `<name>` is an
 * icon of the vendored Bootstrap Icons (IconClass); the class is the only one allowed on `<i>`,
 * an icon is always `aria-hidden` and nothing else gets it (IconAttributes), and text inside an
 * icon is moved after it (it would be hidden from screen readers). No other element gains
 * classes.
 *
 * Responsive images (D-54-01): an `<img>` whose `src` is a stored public image with variants gets
 * `srcset`, `sizes`, the stored `width`/`height`, `loading="lazy"` and `decoding="async"` from
 * the server (ResponsiveImage) after purifying; the purifier itself still refuses those
 * attributes, so nothing the client sends survives.
 */
final class HtmlSanitizer
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        return trim(self::responsiveImages(self::closeIcons(self::purifier()->purify($html))));
    }

    /** `sizes` of images inside rich text (the text column is at most ~800 px wide). */
    public const RICH_TEXT_SIZES = '(min-width: 992px) 800px, 100vw';

    /**
     * Adds the server's responsive attributes to purified `<img>` tags of stored public images
     * with variants; other images stay as they are.
     */
    private static function responsiveImages(string $html): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }

        $images = new ResponsiveImage();

        return (string) preg_replace_callback('#<img\b([^>]*?)\s*/?>#i', static function (array $match) use ($images): string {
            preg_match_all('#\s([a-z-]+)="([^"]*)"#i', $match[1], $pairs, PREG_SET_ORDER);
            $attributes = [];

            foreach ($pairs as $pair) {
                $attributes[strtolower($pair[1])] = $pair[2];
            }

            $src = html_entity_decode($attributes['src'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            try {
                $file       = $src === '' ? null : $images->fromUrl($src);
                $responsive = $file !== null && $file->isImage() ? $images->attributes($file) : null;
            } catch (Throwable) {
                $responsive = null; // no database (e.g. while installing): the image as it is
            }

            if ($responsive === null) {
                return $match[0];
            }

            if ($responsive['srcset'] === '') {
                return $match[0];
            }

            // `src` stays the stored file (what the editor inserted), so cleaning again is stable.
            return '<img src="' . ($attributes['src'] ?? '') . '"'
                . ' srcset="' . esc($responsive['srcset']) . '"'
                . ' sizes="' . self::RICH_TEXT_SIZES . '"'
                . ' alt="' . ($attributes['alt'] ?? '') . '"'
                . ($responsive['width'] !== null && $responsive['height'] !== null ? ' width="' . (int) $responsive['width'] . '" height="' . (int) $responsive['height'] . '"' : '')
                . ' loading="lazy" decoding="async" />';
        }, $html);
    }

    /**
     * Moves the content of an icon after it: `<i class="bi bi-x" aria-hidden="true">text</i>`
     * becomes `<i class="bi bi-x" aria-hidden="true"></i>text`. The purified HTML is well formed,
     * so the `<i>` tags pair up.
     */
    private static function closeIcons(string $html): string
    {
        if (! str_contains($html, '<i class=')) {
            return $html;
        }

        $parts = preg_split('#(<i(?:\s[^>]*)?>|</i>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $stack = [];
        $out   = '';

        foreach ($parts === false ? [$html] : $parts as $index => $part) {
            if ($part === '</i>') {
                if (array_pop($stack) !== 'closed') {
                    $out .= $part;
                }

                continue;
            }

            if (preg_match('#^<i(?:\s[^>]*)?>$#', $part) !== 1) {
                $out .= $part;

                continue;
            }

            $icon = str_starts_with($part, '<i class=');
            $next = $parts[$index + 1] ?? '';

            if ($icon && $next !== '</i>') {
                $out .= $part . '</i>';
                $stack[] = 'closed';
            } else {
                $out .= $part;
                $stack[] = 'open';
            }
        }

        return $out;
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $cache  = WRITEPATH . 'cache/htmlpurifier';

        if (! is_dir($cache)) {
            @mkdir($cache, 0750, true);
        }

        $config->set('Cache.SerializerPath', is_dir($cache) && is_writable($cache) ? $cache : null);
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('HTML.DefinitionID', 'kpskeletor-rich-text');
        $config->set('HTML.DefinitionRev', 3);
        $config->set('HTML.Allowed', 'p[class],br,hr,h2[class],h3[class],h4[class],h5[class],h6[class],strong,b,em,i[class|aria-hidden],u,s,sub,sup,'
            . 'blockquote[class],pre,code,ul,ol,li[class],a[href|title|target],img[src|alt|width|height],'
            . 'table,thead,tbody,tr,th[colspan|rowspan|class],td[colspan|rowspan|class],caption');
        $config->set('Attr.AllowedClasses', array_values(array_unique(AlignmentToClass::CLASSES)));
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetNoopener', true);
        $config->set('HTML.TargetNoreferrer', true);
        $config->set('AutoFormat.RemoveEmpty', true);
        // An empty <i> survives only as an icon (a valid class), D-49-01.
        $config->set('AutoFormat.RemoveEmpty.Predicate', ['colgroup' => [], 'th' => [], 'td' => [], 'iframe' => ['src'], 'i' => ['class']]);

        $config->set('URI.DefinitionID', 'kpskeletor-site-images');
        $config->set('URI.DefinitionRev', 1);
        $uri = $config->maybeGetRawURIDefinition();

        if ($uri !== null) {
            $uri->addFilter(new SiteImagesOnly(), $config);
        }

        $definition = $config->maybeGetRawHTMLDefinition();

        if ($definition !== null) {
            $definition->info_attr_transform_pre['kp-alignment'] = new AlignmentToClass();

            // Icons on <i> (D-49-01): the class of a known icon and aria-hidden, nothing else.
            $icon                                 = $definition->addBlankElement('i');
            $icon->attr['class']                  = new IconClass();
            $icon->attr['aria-hidden']            = new HTMLPurifier_AttrDef_Enum(['true']);
            $icon->attr_transform_post['kp-icon'] = new IconAttributes();
        }

        return self::$purifier = new HTMLPurifier($config);
    }
}
