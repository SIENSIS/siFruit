<?php

declare(strict_types=1);

namespace Modules\Tutorial\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use Modules\Core\Controllers\FrontendController;
use Modules\Tutorial\Services\TaskRepository;

/**
 * The public page of the to-do tasks (tutorial 5): **only `public` to-do tasks** (not in the trash,
 * in an active project or none), grouped by status, whoever is signed in.
 *
 * - `GET tasks`: every public to-do task, with the projects as filters;
 * - `GET tutorial/project/{slug}`: those of one active project (anything else is a 404).
 *
 * SEO metadata through `FrontendController::meta()`; the menu link type `ProjectLink` points here.
 */
final class PublicTasks extends FrontendController
{
    public function index(): string
    {
        $repository = new TaskRepository();

        $this->meta([
            'description' => lang('Tutorial.public.description'),
            'canonical'   => site_url('tutorial'),
            'og'          => true,
        ]);

        return $this->render('Modules\Tutorial\Views\public\index', [
            'title'       => lang('Tutorial.public.title'),
            'intro'       => lang('Tutorial.public.description'),
            'columns'     => $this->columns($repository->publicTasks()),
            'projects'    => $repository->publicProjects(),
            'current'     => null,
            'requestUrl'  => (bool) setting('Tutorial.publicRequests') ? site_url('tutorial/request') : null,
            'breadcrumbs' => [],
        ]);
    }

    public function project(string $slug): string
    {
        $repository = new TaskRepository();
        $project    = $repository->activeProject($slug);

        if ($project === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->meta([
            'description' => $project['description'] !== '' ? mb_substr($project['description'], 0, 300) : lang('Tutorial.public.description'),
            'canonical'   => site_url('tutorial/project/' . rawurlencode($project['slug'])),
            'og'          => true,
        ]);

        return $this->render('Modules\Tutorial\Views\public\index', [
            'title'       => $project['name'],
            'intro'       => $project['description'],
            'columns'     => $this->columns($repository->publicTasks($project['id'])),
            'projects'    => $repository->publicProjects(),
            'current'     => $project['slug'],
            'requestUrl'  => (bool) setting('Tutorial.publicRequests') ? site_url('tutorial/request') : null,
            'breadcrumbs' => [['label' => lang('Tutorial.public.title'), 'url' => site_url('tutorial')], ['label' => $project['name']]],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function columns(array $rows): array
    {
        $columns = array_fill_keys(TaskRepository::STATUSES, []);

        foreach ($rows as $row) {
            $status             = in_array($row['status'], TaskRepository::STATUSES, true) ? (string) $row['status'] : 'todo';
            $columns[$status][] = [
                'title'       => (string) $row['title'],
                'description' => (string) ($row['description'] ?? ''),
                'due'         => is_string($row['due_on']) ? $row['due_on'] : null,
                'labels'      => TaskRepository::labels($row['labels']),
            ];
        }

        return $columns;
    }
}
