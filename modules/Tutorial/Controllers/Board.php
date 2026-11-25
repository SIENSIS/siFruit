<?php

declare(strict_types=1);

namespace Modules\Tutorial\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use Kpcrudy\Crud;
use Modules\Core\Controllers\BackendController;
use Modules\Tutorial\Services\TaskRepository;

/**
 * The board (tutorial 3, resource `tutorial-board`, `tutorial-board.view`, every group): the to-do
 * tasks in three columns (to do, doing, done). Users with `tutorial-tasks.list` see every to-do task,
 * the others only those assigned to them (`TaskRepository::board()`).
 *
 * - `GET backend/tutorial` (`?project=<id>`): the page, drawn on the server (works without
 *   JavaScript); `Assets/js/board.js` (`data-component="tutorial/board"`) adds drag and drop and the
 *   keyboard "move" buttons;
 * - `GET backend/tutorial/board/data` (`?project=`): the same columns as JSON (the component reloads
 *   them after a move);
 * - `POST backend/tutorial/board/{code}/move` (`status`, JSON or form): moves a to-do task. Allowed
 *   with `tutorial-tasks.edit` or to an assignee (`TaskRepository::canMove()`); anything the user may
 *   not see is a 404, may not move a 403. Logged in the activity log.
 */
final class Board extends BackendController
{
    public function index(): string
    {
        $user    = $this->currentUser();
        $project = $this->project();

        return $this->render('Modules\Tutorial\Views\board\index', [
            'title'    => lang('Tutorial.boardTitle'),
            'icon'     => 'bi bi-kanban',
            'columns'  => $this->columns($user, $project),
            'projects' => (new TaskRepository())->projectNames(true),
            'project'  => $project,
            'seesAll'  => TaskRepository::seesAll($user),
            'canAdd'   => $this->can('tutorial-tasks.add'),
            'options'  => [
                'dataUrl' => backend_url('tutorial/board/data') . ($project !== null ? '?project=' . $project : ''),
                'css'     => asset('tutorial::css/tutorial.css'),
                'texts'   => [
                    'moved'      => lang('Tutorial.board.moved'),
                    'moveTo'     => lang('Tutorial.board.moveTo'),
                    'move'       => lang('Tutorial.board.move'),
                    'priorities' => Tasks::options('priorities', TaskRepository::PRIORITIES),
                    'empty'      => lang('Tutorial.board.emptyColumn'),
                    'statuses'   => Tasks::options('statuses', TaskRepository::STATUSES),
                ],
            ],
        ]);
    }

    public function data(): ResponseInterface
    {
        return $this->response->setJSON(['columns' => $this->columns($this->currentUser(), $this->project())]);
    }

    public function move(string $code): ResponseInterface
    {
        $user       = $this->currentUser();
        $repository = new TaskRepository();
        $id         = Crud::unmaskId($code, 'tutorial_tasks');
        $task       = $id === null ? null : $repository->find((int) $id);
        $json       = $this->wantsJson();

        if ($task === null || ! ($repository->canSee($user, $task) || TaskRepository::seesAll($user))) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (! $repository->canMove($user, $task)) {
            security_log('Tutorial', 'move_refused', ['resource' => 'tutorial-board', 'record_id' => (int) $task['id']]);

            return $json
                ? $this->response->setStatusCode(403)->setJSON(['message' => lang('Tutorial.board.forbidden')])
                : redirect()->to(backend_url('tutorial'))->with('error', lang('Tutorial.board.forbidden'));
        }

        $status = $this->postedStatus();

        if ($status === null) {
            return $json
                ? $this->response->setStatusCode(422)->setJSON(['message' => lang('Tutorial.board.invalidStatus')])
                : redirect()->to(backend_url('tutorial'))->with('error', lang('Tutorial.board.invalidStatus'));
        }

        if ($status !== $task['status']) {
            $repository->setStatus((int) $task['id'], $status);

            activity_log('Tutorial', 'moved', [
                'resource'  => 'tutorial-tasks',
                'record_id' => (int) $task['id'],
                'summary'   => (string) $task['title'],
                'changes'   => ['status' => [(string) $task['status'], $status]],
            ]);
        }

        return $json
            ? $this->response->setJSON(['id' => $code, 'status' => $status, 'message' => lang('Tutorial.board.moved')])
            : redirect()->to(backend_url('tutorial'))->with('success', lang('Tutorial.board.moved'));
    }

    /**
     * The board's columns for a user: status => list of cards.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function columns(User $user, ?int $project): array
    {
        $repository = new TaskRepository();
        $projects   = $repository->projectNames();
        $users      = [];
        $rows       = $repository->board($user, $project);
        $columns    = array_fill_keys(TaskRepository::STATUSES, []);
        $editAll    = $this->can('tutorial-tasks.edit');
        $ids        = array_unique(array_merge(...array_map(static fn (array $row): array => $row['assignees'], $rows ?: [['assignees' => []]])));

        if ($ids !== []) {
            foreach (db_connect()->table('users')->select('id, username')->whereIn('id', $ids)->get()->getResultArray() as $row) {
                $users[(int) $row['id']] = (string) $row['username'];
            }
        }

        foreach ($rows as $row) {
            $status = in_array($row['status'], TaskRepository::STATUSES, true) ? (string) $row['status'] : 'todo';
            $code   = Crud::maskId((int) $row['id'], 'tutorial_tasks');
            $due    = is_string($row['due_on']) ? $row['due_on'] : null;

            $columns[$status][] = [
                'id'        => $code,
                'title'     => (string) $row['title'],
                'priority'  => (string) $row['priority'],
                'project'   => $row['project_id'] !== null ? ($projects[(int) $row['project_id']] ?? null) : null,
                'due'       => $due,
                'overdue'   => $due !== null && $status !== 'done' && $due < date('Y-m-d'),
                'labels'    => array_map(static fn (string $label): string => lang('Tutorial.labels.' . $label), TaskRepository::labels($row['labels'])),
                'assignees' => array_values(array_map(static fn (int $id): string => $users[$id] ?? '#' . $id, $row['assignees'])),
                'canMove'   => $editAll || in_array((int) $user->id, $row['assignees'], true),
                'moveUrl'   => backend_url('tutorial/board/' . $code . '/move'),
                'url'       => $this->can('tutorial-tasks.show') ? backend_url('tutorial/tasks/show/' . $code) : null,
            ];
        }

        return $columns;
    }

    private function project(): ?int
    {
        $value = $this->request->getGet('project');

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function postedStatus(): ?string
    {
        $request = $this->request;
        $status  = null;

        if ($request instanceof IncomingRequest) {
            $json   = str_contains($request->getHeaderLine('Content-Type'), 'json') ? $request->getJSON(true) : null;
            $status = is_array($json) ? ($json['status'] ?? null) : $request->getPost('status');
        }

        return is_string($status) && in_array($status, TaskRepository::STATUSES, true) ? $status : null;
    }

    private function wantsJson(): bool
    {
        return str_contains($this->request->getHeaderLine('Accept'), 'json') || $this->request->isAJAX();
    }
}
