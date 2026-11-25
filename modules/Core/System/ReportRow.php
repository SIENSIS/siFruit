<?php

declare(strict_types=1);

namespace Modules\Core\System;

/**
 * One row of the System information report: its cells (the first one is the label), a status
 * (`ok`, `warning`, `error` or null when it does not apply) and a short note explaining a
 * warning. Values are plain text, never secrets (docs/system.md).
 */
final class ReportRow
{
    public const OK      = 'ok';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /**
     * @param list<string> $cells
     */
    public function __construct(
        public readonly array $cells,
        public readonly ?string $status = null,
        public readonly string $note = '',
    ) {
    }

    public function label(): string
    {
        return $this->cells[0] ?? '';
    }

    public function value(): string
    {
        return implode(' · ', array_slice($this->cells, 1));
    }

    public function isProblem(): bool
    {
        return $this->status === self::WARNING || $this->status === self::ERROR;
    }
}
