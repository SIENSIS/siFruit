<?php

declare(strict_types=1);

namespace Modules\Devtools\Inspector;

/**
 * A small Markdown renderer for the module inspector (a module's `CLAUDE.md`): headings,
 * paragraphs, lists, block quotes, fenced code, `code`, **bold**, *italic* and links. Every text
 * is escaped first; links only for http(s) URLs (other targets stay text). No HTML of the file is kept.
 */
final class Markdown
{
    /** @var list<string> */
    private array $html = [];

    /** @var list<string> */
    private array $para = [];

    private ?string $list = null;

    public static function toHtml(string $markdown): string
    {
        return (new self())->render($markdown);
    }

    private function render(string $markdown): string
    {
        $code = null;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if ($code !== null) {
                if (preg_match('/^\s*```/', $line) === 1) {
                    $this->html[] = '<pre tabindex="0" class="small"><code>' . esc(implode("\n", $code)) . '</code></pre>';
                    $code         = null;
                } else {
                    $code[] = $line;
                }

                continue;
            }

            if (preg_match('/^\s*```/', $line) === 1) {
                $this->flush();
                $code = [];

                continue;
            }

            if (trim($line) === '') {
                $this->flush();

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m) === 1) {
                $this->flush();
                $level        = min(6, strlen($m[1]) + 2); // the page has its own h1/h2
                $this->html[] = '<h' . $level . ' class="h6 mt-3">' . self::inline($m[2]) . '</h' . $level . '>';

                continue;
            }

            if (preg_match('/^\s*(?:[-*+]|(\d+)\.)\s+(.*)$/', $line, $m) === 1) {
                $tag = $m[1] !== '' ? 'ol' : 'ul';

                if ($this->para !== [] || $this->list !== $tag) {
                    $this->flush();
                    $this->html[] = '<' . $tag . '>';
                    $this->list   = $tag;
                }

                $this->html[] = '<li>' . self::inline($m[2]) . '</li>';

                continue;
            }

            if ($this->list !== null && preg_match('/^\s{2,}(.*)$/', $line, $m) === 1) {
                // continuation of the last item
                $last         = (string) array_pop($this->html);
                $this->html[] = substr($last, 0, -5) . ' ' . self::inline($m[1]) . '</li>';

                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $m) === 1) {
                $this->flush();
                $this->html[] = '<blockquote class="blockquote small border-start ps-3">' . self::inline($m[1]) . '</blockquote>';

                continue;
            }

            if ($this->list !== null) {
                $this->flush();
            }

            $this->para[] = trim($line);
        }

        if ($code !== null) {
            $this->html[] = '<pre tabindex="0" class="small"><code>' . esc(implode("\n", $code)) . '</code></pre>';
        }

        $this->flush();

        return implode("\n", $this->html);
    }

    private function flush(): void
    {
        if ($this->para !== []) {
            $this->html[] = '<p>' . self::inline(implode(' ', $this->para)) . '</p>';
            $this->para   = [];
        }

        if ($this->list !== null) {
            $this->html[] = '</' . $this->list . '>';
            $this->list   = null;
        }
    }

    private static function inline(string $text): string
    {
        $parts = preg_split('/(`[^`]+`)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out   = '';

        foreach ($parts as $part) {
            if (strlen($part) > 1 && $part[0] === '`' && str_ends_with($part, '`')) {
                $out .= '<code>' . esc(substr($part, 1, -1)) . '</code>';

                continue;
            }

            $part = esc($part);
            $part = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
                $url = html_entity_decode($m[2], ENT_QUOTES);

                // Only web links: a relative path of the repository means nothing in the back office.
                if (preg_match('#^https?://#i', $url) !== 1) {
                    return $m[1];
                }

                return '<a href="' . esc($url) . '" rel="noopener noreferrer">' . $m[1] . '</a>';
            }, $part);
            $part = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $part);
            $part = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $part);
            $out .= $part;
        }

        return $out;
    }
}
