<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Notifier;

/**
 * Tasks. Everyone sees the tasks assigned to them and those they assigned;
 * users whose role covers the whole school see all of them (management board).
 * The assignee may update status and add comments; the rest is for the creator
 * or management.
 */
final class TaskController extends CrudController
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    protected function definition(): array
    {
        $priorities = $statuses = [];
        foreach (self::PRIORITIES as $priority) {
            $priorities[$priority] = __('tasks.priorities.' . $priority);
        }
        foreach (self::STATUSES as $status) {
            $statuses[$status] = __('tasks.statuses.' . $status);
        }
        return [
            'table' => 'tasks', 'entity' => 'task', 'path' => '/tasks', 'lang' => 'tasks', 'module' => 'tasks',
            'order' => "t.status IN ('done', 'cancelled'), t.due_date IS NULL, t.due_date, t.id DESC",
            'softDelete' => true, 'userstamps' => true, 'show' => true, 'nameField' => 'title', 'search' => ['t.title'],
            'listExtra' => ['assignee_name' => 'tasks.fields.assignee_user_id', 'due_label' => 'tasks.due'],
            'headerActions' => [['path' => '/tasks/board', 'label' => 'tasks.board', 'icon' => 'kanban', 'permission' => 'tasks.view']],
            'filters' => [
                'status' => ['label' => 'tasks.fields.status', 'options' => $statuses, 'sql' => 't.status = ?'],
                'priority' => ['label' => 'tasks.fields.priority', 'options' => $priorities, 'sql' => 't.priority = ?'],
                'mine' => ['label' => 'tasks.show', 'options' => ['1' => __('tasks.only_mine')], 'sql' => '(? = 1 AND t.assignee_user_id = ' . (int) $this->auth()->id() . ')'],
            ],
            'fields' => [
                'title' => ['rules' => 'required|max:190', 'list' => true, 'attrs' => 'maxlength="190"'],
                'description' => ['type' => 'textarea', 'rules' => 'max:5000'],
                'assignee_user_id' => ['type' => 'select', 'rules' => 'required', 'options' => fn () => $this->userOptions()],
                'due_date' => ['type' => 'date'],
                'priority' => ['type' => 'select', 'rules' => 'required', 'options' => $priorities, 'list' => true, 'default' => 'normal'],
                'status' => ['type' => 'select', 'rules' => 'required', 'options' => $statuses, 'list' => true, 'default' => 'open'],
            ],
        ];
    }

    /** @return array{0: string, 1: array} */
    private function visibility(): array
    {
        if ($this->accessScope()->isUnrestricted()) {
            return ['1 = 1', []];
        }
        $me = (int) $this->auth()->id();
        return ['(t.assignee_user_id = ? OR t.assigned_by = ?)', [$me, $me]];
    }

    protected function listQuery(): array
    {
        [$where, $params] = $this->visibility();
        $overdue = $this->db()->pdo()->quote(' · ' . __('tasks.overdue'));
        return [
            'select' => ", au.full_name AS assignee_name,
                CONCAT(COALESCE(DATE_FORMAT(t.due_date, '%d/%m/%Y'), '—'),
                       CASE WHEN t.due_date < CURDATE() AND t.status IN ('open', 'in_progress') THEN $overdue ELSE '' END) AS due_label",
            'join' => 'LEFT JOIN users au ON au.id = t.assignee_user_id',
            'where' => $where, 'params' => $params,
        ];
    }

    protected function rowVisible(array $row): bool
    {
        $me = $this->auth()->id();
        return $this->accessScope()->isUnrestricted() || (int) $row['assignee_user_id'] === $me || (int) $row['assigned_by'] === $me;
    }

    protected function rowEditable(array $row): bool
    {
        return (int) $row['assigned_by'] === $this->auth()->id() || $this->accessScope()->isUnrestricted();
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if ($existing === null) {
            $data['assigned_by'] = $this->auth()->id();
        }
        $data['completed_at'] = $data['status'] === 'done' ? ($existing['completed_at'] ?? date('Y-m-d H:i:s')) : null;
        return $data;
    }

    protected function afterSave(int $id, ?array $existing): void
    {
        $task = $this->db()->one('SELECT title, assignee_user_id FROM tasks WHERE id = ?', [$id]);
        $assignee = (int) $task['assignee_user_id'];
        if ($assignee !== $this->auth()->id() && $assignee !== (int) ($existing['assignee_user_id'] ?? 0)) {
            Notifier::send($assignee, $this->schoolId(), 'task', __('tasks.notify_assigned', ['title' => $task['title']]), null, "/tasks/$id");
        }
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/tasks/$id";
    }

    public function show(Request $request, int $id): Response
    {
        $task = $this->find($id);
        return $this->view('tasks/show', [
            'task' => $task,
            'meta' => $this->db()->one(
                'SELECT a.full_name AS assignee_name, b.full_name AS assigner_name FROM tasks t
                   LEFT JOIN users a ON a.id = t.assignee_user_id LEFT JOIN users b ON b.id = t.assigned_by WHERE t.id = ?',
                [$id]
            ),
            'comments' => $this->db()->all(
                'SELECT c.body, c.created_at, u.full_name FROM task_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.task_id = ? ORDER BY c.id',
                [$id]
            ),
            'meetingId' => $task['source_type'] === 'meeting_decision'
                ? $this->db()->value('SELECT meeting_id FROM meeting_decisions WHERE id = ?', [$task['source_id']]) : null,
            'statuses' => $this->fields()['status']['options'],
            'canEdit' => can('tasks.update') && $this->rowEditable($task),
            'overdue' => $task['due_date'] !== null && $task['due_date'] < date('Y-m-d') && in_array($task['status'], ['open', 'in_progress'], true),
        ]);
    }

    /** Status change by the assignee (or anyone who may edit the task). Completing a task that came from a decision closes the decision. */
    public function setStatus(Request $request, int $id): Response
    {
        $task = $this->find($id);
        if (!$this->rowEditable($task) && (int) $task['assignee_user_id'] !== $this->auth()->id()) {
            throw new \App\Core\HttpException(403);
        }
        $v = Validator::make($request->post, ['status' => 'required|in:' . implode(',', self::STATUSES)], 'tasks.fields');
        if ($v->fails()) {
            return $this->invalid("/tasks/$id", $v->errors(), []);
        }
        $status = $v->validated()['status'];
        $this->db()->transaction(function () use ($task, $id, $status): void {
            $this->db()->update('tasks', ['status' => $status, 'completed_at' => $status === 'done' ? date('Y-m-d H:i:s') : null, 'updated_by' => $this->auth()->id()], ['id' => $id]);
            if ($task['source_type'] === 'meeting_decision' && in_array($status, ['done', 'open', 'in_progress'], true)) {
                $this->db()->update('meeting_decisions', ['status' => $status === 'done' ? 'done' : 'open'], ['id' => $task['source_id']]);
            }
        });
        $this->audit('update', 'task', $id, ['status' => $task['status']], ['status' => $status]);
        return $this->redirect("/tasks/$id", 'app.updated');
    }

    public function storeComment(Request $request, int $id): Response
    {
        $this->find($id);
        $v = Validator::make($request->post, ['body' => 'required|max:2000'], 'tasks.comment_fields');
        if ($v->fails()) {
            return $this->invalid("/tasks/$id", $v->errors(), $request->post);
        }
        $this->db()->insert('task_comments', ['school_id' => $this->schoolId(), 'task_id' => $id, 'user_id' => $this->auth()->id(), 'body' => $v->validated()['body']]);
        return $this->redirect("/tasks/$id", 'app.created');
    }

    /** Open work grouped by status. Shows what the user may see: their own tasks, or all of them for management. */
    public function board(Request $request): Response
    {
        [$where, $params] = $this->visibility();
        $columns = ['open' => [], 'in_progress' => [], 'done' => []];
        foreach ($this->db()->all(
            "SELECT t.id, t.title, t.status, t.priority, t.due_date, u.full_name AS assignee_name
               FROM tasks t LEFT JOIN users u ON u.id = t.assignee_user_id
              WHERE t.school_id = ? AND t.deleted_at IS NULL AND $where
                AND (t.status IN ('open', 'in_progress') OR (t.status = 'done' AND t.completed_at > NOW() - INTERVAL 14 DAY))
              ORDER BY t.due_date IS NULL, t.due_date, t.id LIMIT 300",
            [$this->schoolId(), ...$params]
        ) as $task) {
            $columns[$task['status']][] = $task;
        }
        return $this->view('tasks/board', ['columns' => $columns, 'all' => $this->accessScope()->isUnrestricted(), 'today' => date('Y-m-d')]);
    }
}
