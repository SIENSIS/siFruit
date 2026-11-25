<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use CodeIgniter\Test\CIUnitTestCase;
use Modules\Core\Security\HtmlSanitizer;

/**
 * The rich-text sanitiser (HTMLPurifier): no styles, scripts or handlers; alignment kept as the
 * classes text-center / text-right / text-justify (from `style`, `align` and the editor's
 * `ql-align-*`), no other class; icons `<i class="bi bi-<name>" aria-hidden="true">` of the
 * vendored list only (D-49-01).
 *
 * @internal
 */
final class HtmlSanitizerTest extends CIUnitTestCase
{
    public function testAlignmentBecomesClassesAndStylesAreDropped(): void
    {
        $this->assertSame('<p class="text-center">a</p>', HtmlSanitizer::clean('<p style="text-align: center; color: red">a</p>'));
        $this->assertSame('<p class="text-right">a</p>', HtmlSanitizer::clean('<p style="TEXT-ALIGN:end">a</p>'));
        $this->assertSame('<h2 class="text-justify">a</h2>', HtmlSanitizer::clean('<h2 align="justify">a</h2>'));
        $this->assertSame('<p class="text-right">a</p>', HtmlSanitizer::clean('<p class="ql-align-right">a</p>'));
        $this->assertSame('<ul><li class="text-center">a</li></ul>', HtmlSanitizer::clean('<ul><li class="text-center">a</li></ul>'));
        $this->assertSame('<p>a</p>', HtmlSanitizer::clean('<p style="text-align:left" class="evil btn">a</p>'), 'Left is the default; other classes go.');
        $this->assertSame('<p>a</p>', HtmlSanitizer::clean('<p class="text-danger">a</p>'));
        $this->assertSame('<blockquote class="text-center">q</blockquote>', HtmlSanitizer::clean('<blockquote style="text-align:center">q</blockquote>'));
    }

    public function testDangerousMarkupIsRemoved(): void
    {
        $clean = HtmlSanitizer::clean('<p onclick="x()" style="background:url(javascript:alert(1))">t<script>alert(1)</script><a href="javascript:alert(2)">l</a><iframe src="https://evil.example"></iframe></p>');

        $this->assertSame('<p>t<a>l</a></p>', $clean);
    }

    public function testOnlyImagesOfTheSiteItselfAreKept(): void
    {
        $base = rtrim(config('App')->baseURL, '/');

        $this->assertSame('<p><img src="/uploads/modules/news/a.jpg" alt="A cat"></p>', HtmlSanitizer::clean('<p><img src="/uploads/modules/news/a.jpg" alt="A cat"></p>'));
        $this->assertSame('<p><img src="' . $base . '/uploads/x.png" alt=""></p>', HtmlSanitizer::clean('<p><img src="' . $base . '/uploads/x.png" alt=""></p>'));
        $this->assertSame('<p>t</p>', HtmlSanitizer::clean('<p>t<img src="https://tracker.example/pixel.gif" alt="x"></p>'), 'External image removed');
        $this->assertSame('<p>t</p>', HtmlSanitizer::clean('<p>t<img src="//tracker.example/p.gif"></p>'), 'Scheme-relative external image removed');
        $this->assertSame('<p>t</p>', HtmlSanitizer::clean('<p>t<img src="data:image/png;base64,iVBORw0KGgo="></p>'), 'data: images removed');
        $this->assertSame('<p><a href="https://example.org/">link</a></p>', HtmlSanitizer::clean('<p><a href="https://example.org/">link</a></p>'), 'Links to other sites are kept');
    }

    public function testAKnownIconIsKeptAndAlwaysHidden(): void
    {
        $icon = '<i class="bi bi-egg" aria-hidden="true"></i>';

        $this->assertSame('<p>Hi ' . $icon . ' egg</p>', HtmlSanitizer::clean('<p>Hi ' . $icon . ' egg</p>'));
        $this->assertSame('<p>' . $icon . '</p>', HtmlSanitizer::clean('<p><i class="bi bi-egg"></i></p>'), 'aria-hidden is added.');
        $this->assertSame('<p><i class="bi bi-egg-fried" aria-hidden="true"></i></p>', HtmlSanitizer::clean('<p><i class="bi-egg-fried  bi" aria-hidden="false"></i></p>'), 'Normalised; aria-hidden is always "true".');
        $this->assertSame($icon, HtmlSanitizer::clean($icon), 'An icon on its own is not an empty element.');
    }

    public function testAnUnknownIconNameIsRemoved(): void
    {
        $this->assertSame('<p>x</p>', HtmlSanitizer::clean('<p><i class="bi bi-not-an-icon-name" aria-hidden="true"></i>x</p>'));
        $this->assertSame('<p>x</p>', HtmlSanitizer::clean('<p><i class="bi bi-EGG"></i>x</p>'));
        $this->assertSame('<p>x</p>', HtmlSanitizer::clean('<p><i class="bi"></i>x</p>'));
        $this->assertSame('<p>x</p>', HtmlSanitizer::clean('<p><i class="bi-egg"></i>x</p>'), 'Both classes are required.');
    }

    public function testExtraClassesOnAnIconRemoveTheIcon(): void
    {
        $this->assertSame('<p><i>t</i></p>', HtmlSanitizer::clean('<p><i class="bi bi-egg btn">t</i></p>'));
        $this->assertSame('<p>x</p>', HtmlSanitizer::clean('<p><i class="bi bi-egg bi-star"></i>x</p>'), 'One icon per element.');
        $this->assertSame('<p>x</p>', HtmlSanitizer::clean('<p><i class="bi bi-egg text-center"></i>x</p>'));
    }

    public function testStylesAndHandlersOnAnIconAreRemoved(): void
    {
        $this->assertSame(
            '<p><i class="bi bi-egg" aria-hidden="true"></i></p>',
            HtmlSanitizer::clean('<p><i class="bi bi-egg" style="color:red" onclick="alert(1)" id="x" title="t" data-x="1"></i></p>'),
        );
    }

    public function testTextInsideAnIconIsMovedAfterIt(): void
    {
        $this->assertSame('<p><i class="bi bi-egg" aria-hidden="true"></i>egg</p>', HtmlSanitizer::clean('<p><i class="bi bi-egg">egg</i></p>'));
        $this->assertSame(
            '<p><i>a <i class="bi bi-star" aria-hidden="true"></i>b c</i> d</p>',
            HtmlSanitizer::clean('<p><i>a <i class="bi bi-star">b</i> c</i> d</p>'),
        );
    }

    public function testItalicsAreNeverHidden(): void
    {
        $this->assertSame('<p><i>italic</i></p>', HtmlSanitizer::clean('<p><i aria-hidden="true">italic</i></p>'));
        $this->assertSame('<p>x</p>', HtmlSanitizer::clean('<p><i aria-hidden="true"></i>x</p>'), 'An empty <i> that is not an icon goes.');
    }

    public function testNoOtherElementGainsIconClasses(): void
    {
        $this->assertSame(
            '<p>x</p><b>z</b><p>w</p>',
            HtmlSanitizer::clean('<p class="bi bi-egg">x</p><span class="bi bi-egg"></span><b class="bi bi-egg">z</b><p aria-hidden="true">w</p>'),
        );
        $this->assertSame('<h2 class="text-center">t</h2>', HtmlSanitizer::clean('<h2 class="bi bi-egg" style="text-align:center">t</h2>'), 'Alignment still works.');
    }
}
