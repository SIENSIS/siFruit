<?php

declare(strict_types=1);

namespace Modules\Core\System;

/**
 * The PHP extensions the application needs, read from `composer.json` (the one list: `require`
 * `ext-*` are required, `suggest` `ext-*` are recommended), so the System information report
 * and Composer's own platform check never disagree.
 */
final class PhpRequirements
{
    public function __construct(private readonly ?string $composerJson = null)
    {
    }

    /**
     * @return list<string>
     */
    public function required(): array
    {
        return $this->extensions('require');
    }

    /**
     * @return list<string>
     */
    public function recommended(): array
    {
        return array_values(array_diff($this->extensions('suggest'), $this->required()));
    }

    /**
     * @return list<string>
     */
    private function extensions(string $key): array
    {
        $file = $this->composerJson ?? ROOTPATH . 'composer.json';
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $list = is_array($json) && is_array($json[$key] ?? null) ? $json[$key] : [];
        $out  = [];

        foreach (array_keys($list) as $package) {
            if (str_starts_with((string) $package, 'ext-')) {
                $out[] = substr((string) $package, 4);
            }
        }

        sort($out);

        return $out;
    }
}
