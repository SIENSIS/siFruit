<?php
/**
 * A table of rows (lists of cells) for the module inspector.
 *
 * @var list<string>             $headers
 * @var list<array<array-key, mixed>> $rows
 */
$cell = static fn (mixed $v): string => is_bool($v) ? ($v ? '✓' : '—') : (is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
?>
<?php if ($rows === []): ?>
    <p class="text-body-secondary small mb-0"><?= esc(lang('Devtools.inspector.none')) ?></p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm small align-middle mb-0">
            <thead><tr><?php foreach ($headers as $header): ?><th scope="col"><?= esc($header) ?></th><?php endforeach ?></tr></thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr><?php foreach (array_values($row) as $value): ?><td class="text-break font-monospace"><?= esc($cell($value)) ?></td><?php endforeach ?></tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
