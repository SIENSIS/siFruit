<?php

declare(strict_types=1);

namespace Modules\Core\System;

/**
 * A section of the System information report: a key (`application`, `php`, … or a module's own,
 * e.g. `backup`), a title, the column headers and the rows. Modules add theirs from the
 * `systemReport` event (SystemReport::EVENT).
 */
final class ReportSection
{
    /** @var list<ReportRow> */
    private array $rows = [];

    /**
     * @param list<string>                         $headers
     * @param array{url: string, label: string}|null $link
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly array $headers,
        public ?array $link = null,
    ) {
    }

    /**
     * Adds a label/value row.
     */
    public function add(string $label, string $value, ?string $status = null, string $note = ''): self
    {
        $this->rows[] = new ReportRow([$label, $value], $status, $note);

        return $this;
    }

    /**
     * Adds a row of several cells (a table such as the modules).
     *
     * @param list<string> $cells
     */
    public function addCells(array $cells, ?string $status = null, string $note = ''): self
    {
        $this->rows[] = new ReportRow($cells, $status, $note);

        return $this;
    }

    /**
     * @return list<ReportRow>
     */
    public function rows(): array
    {
        return $this->rows;
    }
}
