<?php

declare(strict_types=1);

namespace Modules\Devtools\Components;

use Modules\Core\Frontend\RichTextEditor;

/**
 * The component gallery of Devtools (`backend/devtools/components`, session 53): the theme's
 * components and the `ui_*` helpers rendered with sample data, each with the PHP call that
 * produces it (docs/themes.md). `$suffix` keeps element ids unique when the gallery is drawn
 * twice (light and dark).
 */
final class Gallery
{
    /**
     * @return list<array{id: string, title: string, code: string, html: string}>
     */
    public static function items(string $suffix = ''): array
    {
        helper(['ui', 'theme', 'form']);
        $sample = lang('Devtools.components.sample');
        $editor = '';

        foreach (RichTextEditor::attrs() as $name => $value) {
            $editor .= ' ' . $name . '="' . esc($value, 'attr') . '"';
        }

        $fields = '<div class="mb-3"><label class="form-label" for="gallery-name' . $suffix . '">' . esc(lang('Devtools.components.name')) . '</label>'
            . '<input class="form-control" id="gallery-name' . $suffix . '" name="name" value="' . esc($sample, 'attr') . '"></div>'
            . '<div class="mb-3"><label class="form-label" for="gallery-icon' . $suffix . '">' . esc(lang('Devtools.components.icon')) . '</label>'
            . '<input class="form-control" id="gallery-icon' . $suffix . '" name="icon" value="bi-star" data-icon-picker="name"></div>'
            . '<div class="mb-3"><label class="form-label" for="gallery-body' . $suffix . '">' . esc(lang('Devtools.components.body')) . '</label>'
            . '<textarea class="form-control" id="gallery-body' . $suffix . '" name="body" rows="3"' . $editor . '>&lt;p&gt;' . esc($sample) . '&lt;/p&gt;</textarea></div>';

        $items = [
            ['page_header', "theme_component('page_header', ['title' => 'Books', 'subtitle' => '…', 'icon' => 'bi bi-book', 'actions' => [['label' => 'Add', 'url' => '#', 'icon' => 'bi bi-plus-lg', 'variant' => 'primary']]])",
                static fn (): string => theme_component('page_header', ['title' => $sample, 'subtitle' => lang('Devtools.components.subtitle'), 'icon' => 'bi bi-book', 'breadcrumbs' => [], 'actions' => [['label' => lang('Devtools.components.add'), 'url' => '#', 'icon' => 'bi bi-plus-lg', 'variant' => 'primary']]])],
            ['card', "theme_component('card', ['title' => 'Card', 'icon' => 'bi bi-card-text', 'body' => '<p>…</p>', 'footer' => '…'])",
                static fn (): string => theme_component('card', ['title' => $sample, 'icon' => 'bi bi-card-text', 'body' => '<p class="mb-0">' . esc(lang('Devtools.components.text')) . '</p>', 'footer' => esc(lang('Devtools.components.footer')), 'actions' => [['label' => lang('Devtools.components.open'), 'url' => '#']]])],
            ['form_layout', "theme_component('form_layout', ['action' => '#', 'fields' => \$fields, 'submitLabel' => 'Save', 'cancelUrl' => '#'])  // icon picker: data-icon-picker=\"name\"; editor: RichTextEditor::attrs()",
                static fn (): string => theme_component('form_layout', ['action' => '#', 'method' => 'get', 'fields' => $fields, 'title' => null, 'submitLabel' => lang('Devtools.components.save'), 'cancelUrl' => '#', 'hidden' => [], 'dirtyGuard' => false])],
            ['ui_badge', "ui_badge('Published', 'success', 'bi bi-check')",
                static fn (): string => implode(' ', array_map(static fn (string $v): string => ui_badge($v, $v), ['primary', 'secondary', 'success', 'danger', 'warning', 'info'])) . ' ' . ui_badge($sample, 'success', 'bi bi-check')],
            ['ui_status', "ui_status('published')",
                static fn (): string => implode(' ', array_map(static fn (string $s): string => ui_status($s), ['published', 'draft', 'pending', 'failed', 'archived']))],
            ['ui_date', "ui_date('2026-11-22 10:00:00 UTC', 'datetime')",
                static fn (): string => ui_date(gmdate('Y-m-d H:i:s') . ' UTC', 'datetime') . ' · ' . ui_date(gmdate('Y-m-d') . ' UTC', 'date') . ' · ' . ui_date(gmdate('Y-m-d H:i:s', time() - 7200) . ' UTC', 'relative')],
            ['ui_link', "ui_link('Open', 'https://example.org', 'bi bi-box-arrow-up-right')",
                static fn (): string => ui_link(lang('Devtools.components.open'), backend_url('devtools'), 'bi bi-arrow-right') . ' · ' . ui_link('example.org', 'https://example.org')],
            ['ui_avatar', "ui_avatar('Anna Puig')",
                static fn (): string => ui_avatar('Anna Puig') . ' ' . ui_avatar('Joan Vila', null, 'sm')],
            ['ui_user_link', "ui_user_link('Anna Puig', '#')", static fn (): string => ui_user_link('Anna Puig', '#')],
            ['ui_file_link', "ui_file_link('report.pdf', '#', 524288, 'application/pdf')", static fn (): string => ui_file_link('report.pdf', '#', 524288, 'application/pdf')],
            ['ui_pagination_info', 'ui_pagination_info(2, 20, 57)', static fn (): string => ui_pagination_info(2, 20, 57)],
            ['empty_state', "theme_component('empty_state', ['icon' => 'bi bi-inbox', 'title' => 'Nothing yet', 'message' => '…', 'action' => ['label' => 'Add', 'url' => '#']])",
                static fn (): string => theme_component('empty_state', ['icon' => 'bi bi-inbox', 'title' => lang('Devtools.components.empty'), 'message' => lang('Devtools.components.text'), 'action' => ['label' => lang('Devtools.components.add'), 'url' => '#'], 'compact' => false])],
            ['tabs', "theme_component('tabs', ['id' => 'demo', 'tabs' => [['id' => 'a', 'label' => 'One', 'content' => '…', 'active' => true], …]])",
                static fn (): string => theme_component('tabs', ['id' => 'gallery-tabs' . $suffix, 'tabs' => [['id' => 'one', 'label' => '1', 'content' => '<p class="mt-2">' . esc(lang('Devtools.components.text')) . '</p>', 'active' => true], ['id' => 'two', 'label' => '2', 'content' => '<p class="mt-2">2</p>']]])],
            ['breadcrumbs', "theme_component('breadcrumbs', ['items' => [['label' => 'Home', 'url' => '#'], ['label' => 'Books']]])",
                static fn (): string => theme_component('breadcrumbs', ['items' => [['label' => lang('Devtools.title'), 'url' => '#'], ['label' => $sample]]])],
            ['alerts', '<div class="alert alert-success" role="status">…</div>  // flash messages: ->with(\'success\', …)',
                static fn (): string => implode('', array_map(static fn (string $v): string => '<div class="alert alert-' . $v . ' py-2" role="status">' . esc(lang('Devtools.components.alert', [$v])) . '</div>', ['success', 'info', 'warning', 'danger']))],
        ];

        $out = [];

        foreach ($items as [$id, $code, $render]) {
            $out[] = ['id' => $id, 'title' => $id, 'code' => $code, 'html' => $render()];
        }

        return $out;
    }
}
