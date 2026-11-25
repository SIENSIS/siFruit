<?php

declare(strict_types=1);

namespace Modules\Core\System;

/**
 * The files of `writable/logs` for the read-only Error logs viewer (D-53-01, docs/system.md).
 *
 * Only regular files directly in the folder whose names match CodeIgniter's pattern
 * (`log-YYYY-MM-DD.log`) exist for it; a file is chosen by its **date**, never by a path from the
 * request. A page is read backwards from a byte offset in chunks of at most CHUNK bytes, so a
 * large log never loads into memory; entries are shown newest first, redacted by LogRedactor.
 */
final class LogFiles
{
    public const PATTERN = '/^log-(\d{4}-\d{2}-\d{2})\.log$/';

    /** PSR-3 levels, as CodeIgniter writes them. */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** Bytes read at most per page. */
    public const CHUNK = 262144;

    /** Entries shown at most per page. */
    public const PER_PAGE = 50;

    /** Trace lines kept per entry. */
    public const TRACE_LINES = 30;

    private const ENTRY = '/^(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG) - (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) --> ?(.*)$/';

    private readonly string $dir;

    public function __construct(?string $dir = null, private readonly ?LogRedactor $redactor = null, private readonly int $chunk = self::CHUNK)
    {
        $this->dir = rtrim($dir ?? WRITEPATH . 'logs', '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * The log files, newest first.
     *
     * @return list<array{date: string, name: string, size: int, modified: int}>
     */
    public function files(): array
    {
        $files = [];

        foreach (scandir($this->dir) ?: [] as $name) {
            if (preg_match(self::PATTERN, $name, $m) !== 1 || ! self::validDate($m[1])) {
                continue;
            }

            $path = $this->dir . $name;

            if (is_link($path) || ! is_file($path)) {
                continue;
            }

            $files[] = ['date' => $m[1], 'name' => $name, 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)];
        }

        usort($files, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return $files;
    }

    /**
     * The path of the log of a date, or null when the date is not valid or there is no such
     * regular file (the date is the only input: no traversal).
     */
    public function path(string $date): ?string
    {
        if (! self::validDate($date)) {
            return null;
        }

        $path = $this->dir . 'log-' . $date . '.log';

        if (is_link($path) || ! is_file($path)) {
            return null;
        }

        $real = realpath($path);
        $dir  = realpath($this->dir);

        return $real !== false && $dir !== false && dirname($real) === $dir ? $real : null;
    }

    public static function validDate(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * One page of entries of a log, newest first, read backwards from `$before` (a byte offset;
     * null = the end of the file).
     *
     * @return array{entries: list<array{level: string, date: string, message: string, trace: list<string>, traceLines: int}>, older: int|null, size: int, bytesRead: int}|null
     */
    public function read(string $date, ?int $before = null, string $level = '', string $text = ''): ?array
    {
        $path = $this->path($date);

        if ($path === null) {
            return null;
        }

        $size   = (int) filesize($path);
        $end    = $before === null ? $size : max(0, min($before, $size));
        $start  = max(0, $end - $this->chunk);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        fseek($handle, $start);
        $data = $end > $start ? (string) fread($handle, $end - $start) : '';
        fclose($handle);

        // Lines with their offsets; a chunk that does not start the file drops its first,
        // partial line and starts at the first complete entry.
        $offset = $start;
        $lines  = [];

        foreach (explode("\n", $data) as $line) {
            $lines[] = [$offset, $line];
            $offset += strlen($line) + 1;
        }

        if ($start > 0) {
            array_shift($lines);

            while ($lines !== [] && preg_match(self::ENTRY, rtrim($lines[0][1], "\r")) !== 1) {
                array_shift($lines);
            }

            if ($lines === []) {
                // No entry starts in this chunk (one huge entry): skip the chunk.
                return ['entries' => [], 'older' => $start, 'size' => $size, 'bytesRead' => strlen($data)];
            }
        }

        $entries = [];
        $current = null;

        foreach ($lines as [$lineOffset, $line]) {
            $line = rtrim($line, "\r");

            if (preg_match(self::ENTRY, $line, $m) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = ['offset' => $lineOffset, 'level' => strtolower($m[1]), 'date' => $m[2], 'message' => $m[3], 'trace' => []];
            } elseif ($current !== null && $line !== '') {
                $current['trace'][] = $line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        // The newest PER_PAGE entries of the chunk; older ones start the next page.
        $older = $start > 0 ? $start : null;

        if (count($entries) > self::PER_PAGE) {
            $entries = array_slice($entries, -self::PER_PAGE);
            $older   = $entries[0]['offset'];
        } elseif ($entries !== [] && $start > 0) {
            $older = $entries[0]['offset'];
        }

        $redactor = $this->redactor ?? new LogRedactor();
        $level    = in_array($level, self::LEVELS, true) ? $level : '';
        $needle   = mb_strtolower(trim($text));
        $out      = [];

        foreach (array_reverse($entries) as $entry) {
            if ($level !== '' && $entry['level'] !== $level) {
                continue;
            }

            $message = $redactor->redact($entry['message']);
            $trace   = array_map($redactor->redact(...), array_slice($entry['trace'], 0, self::TRACE_LINES));

            // The text filter sees only the masked text (no way to guess a secret by searching).
            if ($needle !== '' && ! str_contains(mb_strtolower($message . "\n" . implode("\n", $trace)), $needle)) {
                continue;
            }

            $out[] = ['level' => $entry['level'], 'date' => $entry['date'], 'message' => $message, 'trace' => $trace, 'traceLines' => count($entry['trace'])];
        }

        return ['entries' => $out, 'older' => $older !== null && $older > 0 ? $older : null, 'size' => $size, 'bytesRead' => strlen($data)];
    }

    /**
     * Entries of `error` or worse in the last CHUNK bytes of a date's log (the hint on System
     * information; a bounded read, so "at least" on a very large file).
     */
    public function errorCount(string $date): int
    {
        $path = $this->path($date);

        if ($path === null) {
            return 0;
        }

        $size   = (int) filesize($path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return 0;
        }

        fseek($handle, max(0, $size - $this->chunk));
        $data = (string) fread($handle, min($size, $this->chunk) ?: 1);
        fclose($handle);

        return (int) preg_match_all('/^(?:EMERGENCY|ALERT|CRITICAL|ERROR) - \d{4}-/m', $data);
    }
}
