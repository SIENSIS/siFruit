<?php

declare(strict_types=1);

namespace Modules\Tutorial\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Kpcrudy\Crud;
use Modules\Core\Controllers\BackendController;
use Modules\Tutorial\Services\TaskRequests;

/**
 * Back office of the requests sent from the public form (tutorial 12), `backend/tutorial/requests`
 * (resource `tutorial-requests`): read-only list and details, delete, export, and the operations
 * `accept` (creates a private to-do task from the request and opens it) and `reject`, each with
 * its permission declared in the manifest. Requests are never edited.
 */
final class Requests extends BackendController
{
    public function index(): ResponseInterface|string
    {
        $crud = $this->crud('tutorial-requests');

        $crud->setTable('tutorial_task_requests')
            ->setSubject(lang('Tutorial.request'), lang('Tutorial.requests'))
            ->unsetAdd()
            ->unsetEdit()
            ->fieldType('status', 'select', ['options' => Tasks::options('requestStatuses', TaskRequests::STATUSES)])
            ->fieldType('details', 'textarea')
            ->setRelation('task_id', 'tutorial_tasks', 'title')
            ->callbackColumn('status', static fn ($value): string => self::statusBadge((string) $value))
            ->callbackExport('status', static fn ($value): string => (string) $value)
            ->columns(['title', 'name', 'email', 'status', 'created_at'])
            ->showFields(['title', 'details', 'name', 'email', 'locale', 'status', 'task_id', 'created_at', 'updated_at'])
            ->exportColumns(['title', 'details', 'name', 'email', 'locale', 'status', 'created_at'])
            ->searchFields(['title', 'name', 'email'])
            ->filters(['status'])
            ->displayAs([
                'title'      => lang('Tutorial.fields.title'),
                'details'    => lang('Tutorial.fields.details'),
                'name'       => lang('Tutorial.fields.requesterName'),
                'email'      => lang('Tutorial.fields.email'),
                'locale'     => lang('Tutorial.fields.locale'),
                'status'     => lang('Tutorial.fields.status'),
                'task_id'    => lang('Tutorial.fields.task'),
                'created_at' => lang('Tutorial.fields.created_at'),
                'updated_at' => lang('Tutorial.fields.updated_at'),
            ])
            ->defaultOrder('id', 'desc');

        $crud->addOperation('accept', static function (string $id, array $row, Crud $crud): ResponseInterface {
            $taskId = (new TaskRequests())->accept($row, (int) auth()->id());

            if ($taskId === null) {
                return $crud->backToList('danger', lang('Tutorial.requestNotNew'));
            }

            session()->setFlashdata('success', lang('Tutorial.requestAccepted'));

            return redirect()->to(backend_url('tutorial/tasks/edit/' . Crud::maskId($taskId, 'tutorial_tasks')));
        }, [
            'label'      => lang('Tutorial.accept'),
            'icon'       => 'bi bi-check2',
            'class'      => 'btn-outline-success',
            'confirm'    => lang('Tutorial.acceptConfirm'),
            'permission' => 'accept',
            'visible'    => static fn (?array $row): bool => $row === null || ($row['status'] ?? '') === 'new',
        ]);

        $crud->addOperation('reject', static function (string $id, array $row, Crud $crud): ResponseInterface|string {
            return (new TaskRequests())->reject($row) ? lang('Tutorial.requestRejected') : $crud->backToList('danger', lang('Tutorial.requestNotNew'));
        }, [
            'label'      => lang('Tutorial.reject'),
            'icon'       => 'bi bi-x-lg',
            'confirm'    => lang('Tutorial.rejectConfirm'),
            'permission' => 'reject',
            'visible'    => static fn (?array $row): bool => $row === null || ($row['status'] ?? '') === 'new',
        ]);

        return $this->renderCrud($crud);
    }

    private static function statusBadge(string $status): string
    {
        $colour = ['new' => 'warning', 'accepted' => 'success', 'rejected' => 'secondary'][$status] ?? 'secondary';

        return in_array($status, TaskRequests::STATUSES, true) ? ui_badge(lang('Tutorial.requestStatuses.' . $status), $colour) : esc($status);
    }
}
