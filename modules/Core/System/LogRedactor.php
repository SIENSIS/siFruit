<?php

declare(strict_types=1);

namespace Modules\Core\System;

/**
 * Masks what looks like a secret in a log line before the Error logs viewer shows it (D-53-01,
 * docs/system.md): the values of keys named like a password, a secret, a token, a key, an
 * authorization or a cookie; bearer/basic credentials; the literal values of the secrets of the
 * configuration (`.env`: encryption key, mask key, database and mail passwords, API keys); and
 * long random-looking strings after `=`. The result is plain text: the view escapes it.
 */
final class LogRedactor
{
    public const MASK = '[masked]';

    /** Key names whose values are masked (part of the name, case-insensitive). */
    private const KEYS = 'password|passwd|pass|pwd|secret|token|api[_-]?key|apikey|key|authorization|cookie|session[_-]?id|signature|credential';

    /** Shortest configured secret replaced literally (shorter values would mask ordinary words). */
    private const MIN_SECRET = 6;

    /** @var list<string> */
    private array $secrets;

    /**
     * @param list<string>|null $secrets Literal values to mask; null = those of the configuration
     */
    public function __construct(?array $secrets = null)
    {
        $secrets = $secrets ?? self::configuredSecrets();
        $secrets = array_values(array_unique(array_filter($secrets, static fn (string $s): bool => strlen($s) >= self::MIN_SECRET)));
        // Longest first, so a secret that contains another is masked whole.
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->secrets = $secrets;
    }

    public function redact(string $text): string
    {
        // 1. Literal secrets of the configuration, wherever they appear.
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        // 2. Credentials of an Authorization header (`Bearer abc`, `Basic dXNlcjpwYXNz`).
        $text = (string) preg_replace('/\b(Bearer|Basic|Token|Digest)\s+[A-Za-z0-9\-._~+\/]{6,}=*/i', '$1 ' . self::MASK, $text);

        // 3. Values of secret-looking keys: `password=x`, `"token":"x"`, `'key' => 'x'`, `Cookie: x`.
        $text = (string) preg_replace_callback(
            '/(?<key>[A-Za-z0-9_.\-]*(?:' . self::KEYS . ')[A-Za-z0-9_.\-]*)(?<sep>["\']?\s*(?:=>|=|:)\s*)(?:(?<q>["\'])(?<qv>[^"\']*)\k<q>|(?<v>[^\s,;&}\])"\'<>]+))/i',
            static function (array $m): string {
                $quoted = isset($m['q']) && $m['q'] !== '';
                $value  = $quoted ? $m['qv'] : ($m['v'] ?? '');

                if ($value === '' || str_starts_with($value, self::MASK) || str_starts_with($value, '[masked')) {
                    return $m[0];
                }

                return $m['key'] . $m['sep'] . ($quoted ? $m['q'] . self::MASK . $m['q'] : self::MASK);
            },
            $text,
        );

        // 4. Long random-looking strings after `=` (keys, hashes, signatures in URLs).
        return (string) preg_replace_callback(
            '/=(\s*)([A-Za-z0-9+\/_\-]{24,}={0,2})/',
            static fn (array $m): string => preg_match('/\d/', $m[2]) === 1 && preg_match('/[A-Za-z]/', $m[2]) === 1 ? '=' . $m[1] . self::MASK : $m[0],
            $text,
        );
    }

    /**
     * The secret values of the configuration and the environment (`.env` keys named like a
     * secret), to be masked literally.
     *
     * @return list<string>
     */
    public static function configuredSecrets(): array
    {
        $values = [];

        try {
            $values[] = (string) config('Encryption')->key;
            $values[] = (string) config('App')->maskKey;
            $database = config('Database');

            foreach (['default', 'tests'] as $group) {
                if (is_array($database->{$group} ?? null)) {
                    $values[] = (string) ($database->{$group}['password'] ?? '');
                }
            }

            $values[] = (string) config('Email')->SMTPPass;
        } catch (\Throwable) {
            // Configuration not available: the environment below still applies.
        }

        foreach ([$_ENV, $_SERVER] as $source) {
            foreach ($source as $name => $value) {
                if (is_string($name) && is_string($value) && preg_match('/(' . self::KEYS . ')/i', $name) === 1) {
                    $values[] = $value;
                }
            }
        }

        // A hex2bin: / base64: key is also masked without its prefix.
        foreach ($values as $value) {
            if (preg_match('/^(?:hex2bin|base64):(.+)$/', $value, $m) === 1) {
                $values[] = $m[1];
            }
        }

        return array_values(array_filter($values, static fn (string $v): bool => trim($v) !== ''));
    }
}
