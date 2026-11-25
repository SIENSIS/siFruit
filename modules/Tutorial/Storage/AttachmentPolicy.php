<?php

declare(strict_types=1);

namespace Modules\Tutorial\Storage;

use CodeIgniter\Shield\Entities\User;
use Modules\Core\Storage\StoredFile;
use Modules\Files\Policies\FileAccessPolicy;
use Modules\Tutorial\Services\TaskRepository;

/**
 * Who reads the private files of `modules/tutorial` (tutorial 10): they follow the visibility of the
 * to-do task they are attached to (`tutorial_task_attachments`), not the folder ACL. Registered in
 * `Config/Events.php` (`filesAccessPolicies`). Default deny:
 *
 * - attached to a **public** to-do task (not in the trash): anyone, guests too;
 * - attached to a private one: a signed-in user who may see it (`tutorial-tasks.show`, its creator
 *   or one of its assignees);
 * - not attached yet (just uploaded through the picker): only the user who uploaded it.
 */
final class AttachmentPolicy implements FileAccessPolicy
{
    public function folder(): string
    {
        return AttachmentUploads::FOLDER;
    }

    public function browse(): string
    {
        return 'hidden';
    }

    public function canView(?User $user, StoredFile $file): bool
    {
        $db    = db_connect();
        $tasks = $db->table('tutorial_tasks')->select('tutorial_tasks.id, tutorial_tasks.visibility, tutorial_tasks.created_by, tutorial_tasks.deleted_at')
            ->join('tutorial_task_attachments', 'tutorial_task_attachments.task_id = tutorial_tasks.id')
            ->where('tutorial_task_attachments.file_key', $file->key)
            ->get()->getResultArray();

        if ($tasks === []) {
            return $user !== null && $file->uploadedBy !== null && (int) $file->uploadedBy === (int) $user->id;
        }

        $repository = new TaskRepository();

        foreach ($tasks as $task) {
            if ($task['deleted_at'] === null && $task['visibility'] === 'public') {
                return true;
            }

            if ($user !== null && $repository->canSee($user, $task)) {
                return true;
            }
        }

        return false;
    }
}
