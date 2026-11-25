<?php

declare(strict_types=1);

namespace Modules\Core\Storage;

/**
 * The references the `FileReferenceProvider`s gave, normalised for `UnusedFiles` (D-55-01):
 * stored-file ids, 32-hex tokens (file keys and the random names of stored files and their
 * variants) and disk paths of public files (from site URLs and paths). A stored file is used when
 * its id, its key, the hex of its name or its path (or one of its variants' paths) is in the set.
 */
final class FileReferenceSet
{
    /** @var array<int, true> */
    private array $ids = [];

    /** @var array<string, true> */
    private array $tokens = [];

    /** @var array<string, true> "<disk>|<path>" */
    private array $paths = [];

    /**
     * Adds a reference: an id (int), a key, a site URL or path, or a text to scan.
     */
    public function add(mixed $reference): void
    {
        if (is_int($reference)) {
            if ($reference > 0) {
                $this->ids[$reference] = true;
            }

            return;
        }

        if (! is_string($reference) || $reference === '') {
            return;
        }

        if (preg_match('/^[a-f0-9]{32}$/', $reference) === 1) {
            $this->tokens[$reference] = true;

            return;
        }

        $prefixes = RichTextFiles::prefixes();

        foreach (RichTextFiles::references($reference) as $found) {
            if ($found[0] !== '/') {
                $this->tokens[$found] = true;

                continue;
            }

            foreach ($prefixes as $disk => $prefix) {
                if (str_starts_with($found, '/' . $prefix . '/')) {
                    $this->paths[$disk . '|' . substr($found, strlen($prefix) + 2)] = true;
                }
            }
        }
    }

    /**
     * Whether a `stored_files` row (id, file_key, disk, path) is referenced.
     *
     * @param array<string, mixed> $row
     */
    public function contains(array $row): bool
    {
        if (isset($this->ids[(int) ($row['id'] ?? 0)]) || isset($this->tokens[(string) ($row['file_key'] ?? '')])) {
            return true;
        }

        $path = (string) ($row['path'] ?? '');

        if (preg_match('/^([a-f0-9]{32})/', basename($path), $match) === 1 && isset($this->tokens[$match[1]])) {
            return true;
        }

        return isset($this->paths[($row['disk'] ?? '') . '|' . $path]);
    }

    /**
     * Whether one of these paths (a file's variants) of a disk is referenced.
     *
     * @param list<string> $paths
     */
    public function containsPath(string $disk, array $paths): bool
    {
        foreach ($paths as $path) {
            if (isset($this->paths[$disk . '|' . $path])) {
                return true;
            }

            if (preg_match('/^([a-f0-9]{32})/', basename($path), $match) === 1 && isset($this->tokens[$match[1]])) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->ids) + count($this->tokens) + count($this->paths);
    }
}
