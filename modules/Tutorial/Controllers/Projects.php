<?php

declare(strict_types=1);

namespace Modules\Tutorial\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Modules\Core\Content\ContentTranslations;
use Modules\Core\Controllers\BackendController;
use Modules\Core\Text\Slugger;

/**
 * Back office of the projects, `backend/tutorial/projects` (resource `tutorial-projects`). The name and
 * description are translated (tutorial 2: `setTranslations()` over `tutorial_project_translations`,
 * the site's languages, its default one required and the fallback in the list); the slug is the public URL
 * `tutorial/project/<slug>` (generated from the default language's name when empty); `ends_on` is the deadline
 * shown in the calendar by the provider `ProjectDeadlines` (pull).
 */
final class Projects extends BackendController
{
    public function index(): ResponseInterface|string
    {
        $crud = $this->crud('tutorial-projects');

        $crud->setTable('tutorial_projects')
            ->setSubject(lang('Tutorial.project'), lang('Tutorial.projects'))
            ->setTranslations('tutorial_project_translations', 'project_id', 'locale', ['name', 'description'], ContentTranslations::locales(), ContentTranslations::crudOptions([
                'types' => ['name' => 'text', 'description' => 'textarea'],
                'rules' => ['name' => 'required|max_length[120]', 'description' => 'permit_empty|max_length[2000]'],
            ]))
            ->fieldType('color', 'color')
            ->fieldType('ends_on', 'date')
            ->fieldType('active', 'boolean')
            ->columns(['name', 'color', 'ends_on', 'active'])
            ->fields(['name', 'description', 'slug', 'color', 'ends_on', 'active'])
            ->showFields(['name', 'description', 'slug', 'color', 'ends_on', 'active', 'created_at', 'updated_at'])
            ->exportColumns(['name', 'slug', 'color', 'ends_on', 'active'])
            ->searchFields(['name', 'slug'])
            ->filters(['active'])
            ->setRules([
                'slug'    => Slugger::rules(),
                'color'   => ['required', 'regex_match[/^#[0-9a-fA-F]{6}$/]'],
                'ends_on' => 'permit_empty|valid_date[Y-m-d]',
            ])
            ->setDefault('color', '#0d6efd')
            ->setDefault('active', 1)
            ->setHelp('slug', lang('Tutorial.help.slug', [ContentTranslations::defaultName()]))
            ->setHelp('ends_on', lang('Tutorial.help.endsOn'))
            ->displayAs([
                'name'        => lang('Tutorial.fields.name'),
                'description' => lang('Tutorial.fields.description'),
                'slug'        => lang('Tutorial.fields.slug'),
                'color'       => lang('Tutorial.fields.color'),
                'ends_on'     => lang('Tutorial.fields.ends_on'),
                'active'      => lang('Tutorial.fields.active'),
                'created_at'  => lang('Tutorial.fields.created_at'),
                'updated_at'  => lang('Tutorial.fields.updated_at'),
            ])
            ->beforeInsert(static fn (array $data): array => self::slug($data, null, ContentTranslations::defaultText($crud->postedTranslations(), 'name')))
            ->beforeUpdate(static fn (array $data, string $id): array => self::slug($data, (int) $id, ContentTranslations::defaultText($crud->postedTranslations(), 'name')))
            ->defaultOrder('id', 'desc');

        return $this->renderCrud($crud);
    }

    /**
     * A unique slug: the one given, or one made from the default language's name.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function slug(array $data, ?int $id, string $name): array
    {
        $slug = trim((string) ($data['slug'] ?? ''));

        if ($id === null || array_key_exists('slug', $data)) {
            $data['slug'] = Slugger::unique('tutorial_projects', $slug !== '' ? $slug : $name, $id, fallback: 'project');
        }

        return $data;
    }
}
