<?php

declare(strict_types=1);

namespace Modules\Tutorial\Menu;

use Modules\Core\Menus\MenuLinkType;
use Modules\Tutorial\Services\TaskRepository;

/**
 * Menu link type "To-do tasks" offered to the public menu editor (tutorial 5, `$menuLinks`): the
 * public page of the to-do tasks (value `*`) or of one active project (value = its slug).
 */
final class ProjectLink implements MenuLinkType
{
    public function key(): string
    {
        return 'tutorial.project';
    }

    public function label(): string
    {
        return lang('Tutorial.menuLink.label');
    }

    /**
     * @return array<string, string> slug => project name ('*' = every public to-do task)
     */
    public function options(): array
    {
        $options = ['*' => lang('Tutorial.menuLink.all')];
        $slugs   = db_connect()->table('tutorial_projects')->select('id, slug')->where('active', 1)->get()->getResultArray();
        $names   = (new TaskRepository())->projectNames(true);

        foreach ($slugs as $row) {
            $options[(string) $row['slug']] = $names[(int) $row['id']] ?? (string) $row['slug'];
        }

        return $options;
    }

    /**
     * The public URL of a choice, or null when the project no longer exists (the item is hidden).
     */
    public function url(string $value): ?string
    {
        if ($value === '*') {
            return site_url('tutorial');
        }

        return array_key_exists($value, $this->options()) ? site_url('tutorial/project/' . rawurlencode($value)) : null;
    }
}
