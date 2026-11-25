<?php
/**
 * Devtools › Module inspector › one module (session 53): the manifest as the Core resolves it.
 *
 * @var string               $layout
 * @var array<string, mixed> $report
 * @var string|null          $claude rendered CLAUDE.md (escaped Markdown)
 */
$card = function (string $title, string $icon, string $body, string $key): string {
    return '<div class="col-12" data-inspector="' . esc($key, 'attr') . '">' . theme_component('card', ['title' => $title, 'icon' => $icon, 'body' => $body]) . '</div>';
};
$table = fn (array $headers, array $rows): string => $this->setData(['headers' => $headers, 'rows' => $rows])->include('Modules\Devtools\Views\modules\_table');
$general = $report['general'] ?? [];
?>
<?= $this->extend($layout) ?>
<?= $this->section('content') ?>
<?= $this->include('Modules\Devtools\Views\_banner') ?>
<?= $this->setVar('active', 'inspector')->include('Modules\Devtools\Views\_nav') ?>
<div class="row g-3">
    <?php if (($report['errors'] ?? []) !== [] || ($report['problems'] ?? []) !== []): ?>
        <div class="col-12">
            <div class="alert alert-warning mb-0" data-inspector-problems>
                <strong><?= esc(lang('Devtools.inspector.problems')) ?></strong>
                <ul class="mb-0 small">
                    <?php foreach ([...$report['errors'], ...$report['problems']] as $problem): ?>
                        <li><?= esc((string) $problem) ?></li>
                    <?php endforeach ?>
                </ul>
            </div>
        </div>
    <?php else: ?>
        <div class="col-12"><div class="alert alert-success mb-0 py-2" data-inspector-problems="0"><?= esc(lang('Devtools.inspector.noProblems')) ?></div></div>
    <?php endif ?>

    <?php
    $rows = [];
    foreach ($general as $key => $value) {
        $rows[] = [lang('Devtools.inspector.general.' . $key), $value === null ? '—' : $value];
    }
    ?>
    <?= $card(lang('Devtools.inspector.sections.general'), 'bi bi-info-circle', $table([lang('Install.about.item'), lang('Install.about.value')], $rows), 'general') ?>

    <?php if (isset($report['requires'])): ?>
        <?= $card(lang('Devtools.inspector.sections.requires'), 'bi bi-diagram-3', $table([lang('Core.list.module'), lang('Devtools.inspector.constraint'), lang('Core.list.version'), lang('Devtools.inspector.met')], array_map(static fn (array $r): array => [$r['module'], $r['constraint'], $r['version'] ?? '—', $r['met']], $report['requires'])), 'requires') ?>
        <?= $card(lang('Devtools.inspector.sections.permissions'), 'bi bi-key', $table([lang('Devtools.inspector.permission'), lang('Devtools.inspector.description'), lang('Devtools.inspector.groups')], array_map(static fn (array $p): array => [$p['name'], $p['description'], implode(', ', $p['groups'])], $report['permissions'])), 'permissions') ?>

        <?php
        ob_start();
        ?>
        <?php if ($report['routes'] === []): ?>
            <p class="text-body-secondary small mb-0"><?= esc(lang('Devtools.inspector.none')) ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm small align-middle mb-0">
                    <thead><tr><th scope="col"><?= esc(lang('Devtools.routes.method')) ?></th><th scope="col"><?= esc(lang('Devtools.routes.path')) ?></th><th scope="col"><?= esc(lang('Devtools.routes.name')) ?></th><th scope="col"><?= esc(lang('Devtools.routes.handler')) ?></th><th scope="col"><?= esc(lang('Devtools.routes.filters')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($report['routes'] as $route): ?>
                            <tr<?= $route['problem'] !== '' ? ' class="table-warning" data-route-problem' : '' ?>>
                                <td><?= esc($route['method']) ?></td>
                                <td class="font-monospace text-break"><?= esc($route['path']) ?><?php if ($route['problem'] !== ''): ?><div class="text-danger"><?= esc($route['problem']) ?></div><?php endif ?></td>
                                <td class="font-monospace"><?= esc($route['name']) ?></td>
                                <td class="font-monospace text-break"><?= esc($route['handler']) ?></td>
                                <td class="font-monospace text-break"><?= esc(implode(', ', $route['filters'])) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
        <?= $card(lang('Devtools.inspector.sections.routes'), 'bi bi-signpost-split', (string) ob_get_clean(), 'routes') ?>

        <?= $card(lang('Devtools.inspector.sections.menu'), 'bi bi-list', $table(['zone', 'key', 'parent', 'permission', 'label', 'order'], array_map(static fn (array $m): array => [$m['zone'] ?? 'sidebar', $m['resource'] ?? $m['route'] ?? $m['group'] ?? '', $m['parent'] ?? '', $m['permission'] ?? '', lang((string) ($m['label'] ?? '')), $m['order'] ?? ''], $report['menu'])), 'menu') ?>
        <?= $card(lang('Devtools.inspector.sections.settings'), 'bi bi-sliders', $table([lang('Devtools.inspector.key'), lang('Devtools.inspector.type'), lang('Devtools.inspector.group'), lang('Devtools.inspector.default'), lang('Devtools.inspector.current')], array_map(static fn (array $s): array => [$s['key'], $s['type'], $s['group'], $s['default'], $s['value']], $report['settings'])), 'settings') ?>

        <?php foreach ($report['declarations'] as $kind => $entries): ?>
            <?php
            $headers = array_keys(array_merge(...array_map(static fn (array $e): array => $e, $entries)));
            $rows    = array_map(static fn (array $e): array => array_map(static fn (string $h): mixed => $e[$h] ?? '', $headers), $entries);
            ?>
            <?= $card(lang('Devtools.inspector.sections.' . $kind), 'bi bi-puzzle', $table($headers, $rows), $kind) ?>
        <?php endforeach ?>

        <?= $card(lang('Devtools.inspector.sections.api'), 'bi bi-plug', $table([lang('Devtools.inspector.key'), lang('Install.about.value')], array_map(static fn ($k, $v): array => [$k, $v], array_keys($report['api']), $report['api'])), 'api') ?>
        <?= $card(lang('Devtools.inspector.sections.security'), 'bi bi-shield', $table([lang('Devtools.inspector.key'), lang('Install.about.value')], [['csp', $report['csp']], ['outboundHosts', $report['outboundHosts']], ['backup', $report['backup']], ['defaultGrants', $report['defaultGrants']]]), 'security') ?>
        <?= $card(lang('Devtools.inspector.sections.migrations'), 'bi bi-database', $table([lang('Devtools.inspector.migration'), lang('Devtools.inspector.state')], array_map(static fn (array $m): array => [$m['name'], lang($m['pending'] ? 'Devtools.inspector.pending' : 'Devtools.inspector.run')], $report['migrations'])), 'migrations') ?>
        <?= $card(lang('Devtools.inspector.sections.events'), 'bi bi-broadcast', $table([lang('Devtools.inspector.event')], array_map(static fn (string $e): array => [$e], $report['events'])), 'events') ?>
        <?php if ($report['other'] !== []): ?>
            <?= $card(lang('Devtools.inspector.sections.other'), 'bi bi-three-dots', $table([lang('Devtools.inspector.key'), lang('Install.about.value')], array_map(static fn ($k, $v): array => [$k, $v], array_keys($report['other']), $report['other'])), 'other') ?>
        <?php endif ?>
    <?php endif ?>
    <?php if ($claude !== null): ?>
        <?= $card('CLAUDE.md', 'bi bi-markdown', '<div class="devtools-markdown small" data-inspector-claude>' . $claude . '</div>', 'claude') ?>
    <?php endif ?>
</div>
<?= $this->endSection() ?>
