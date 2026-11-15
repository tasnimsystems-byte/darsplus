<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\DocumentService;
use App\Services\GoalService;

/**
 * Annual goals with their indicators (KPI), actions and progress history.
 * Everyone with work_plan.view sees the whole plan. Changing a goal needs
 * work_plan.update and, for users with a restricted scope, being its owner.
 */
final class GoalController extends CrudController
{
    protected function definition(): array
    {
        $schoolId = $this->schoolId();
        $yearId = (int) ($this->auth()->year()['id'] ?? 0);
        $statuses = [];
        foreach (GoalService::STATUSES as $status) {
            $statuses[$status] = __('goals.statuses.' . $status);
        }
        $users = fn () => $this->options(
            'SELECT u.id, u.full_name AS label FROM users u WHERE u.deleted_at IS NULL AND u.is_active = 1
                AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.school_id = ?) ORDER BY u.full_name',
            [$schoolId]
        );
        return [
            'table' => 'annual_goals', 'entity' => 'goal', 'path' => '/goals', 'lang' => 'goals', 'module' => 'work_plan',
            'scope' => 'year', 'order' => 't.end_date, t.id', 'softDelete' => true, 'userstamps' => true, 'show' => true,
            'nameField' => 'title', 'search' => ['t.title'],
            'filters' => ['status' => ['label' => 'goals.fields.status', 'options' => $statuses, 'sql' => 't.status = ?']],
            'listExtra' => ['progress_label' => 'goals.fields.progress_percent'],
            'fields' => [
                'title' => ['rules' => 'required|max:190', 'list' => true, 'attrs' => 'maxlength="190"'],
                'plan_objective_id' => ['type' => 'select', 'rules' => 'required', 'list' => true, 'options' => fn () => $this->options(
                    'SELECT o.id, o.title AS label FROM plan_objectives o JOIN work_plans p ON p.id = o.work_plan_id
                      WHERE o.school_id = ? AND p.academic_year_id = ? ORDER BY o.sort_order, o.id',
                    [$schoolId, $yearId]
                ), 'help' => 'goals.objective_help'],
                'domain_id' => ['type' => 'select', 'options' => fn () => $this->options(
                    'SELECT id, name AS label FROM pedagogical_domains WHERE school_id = ? ORDER BY name',
                    [$schoolId]
                )],
                'description' => ['type' => 'textarea', 'rules' => 'max:5000'],
                'baseline_description' => ['type' => 'textarea', 'rules' => 'max:2000', 'help' => 'goals.baseline_help'],
                'target_description' => ['type' => 'textarea', 'rules' => 'max:2000'],
                'success_measure' => ['type' => 'textarea', 'rules' => 'max:2000'],
                'owner_user_id' => ['type' => 'select', 'options' => $users, 'list' => true],
                'start_date' => ['type' => 'date', 'rules' => 'required'],
                'end_date' => ['type' => 'date', 'rules' => 'required', 'list' => true],
                'status' => ['type' => 'select', 'rules' => 'required', 'options' => $statuses, 'list' => true, 'default' => 'not_started'],
                'effectiveness_review' => ['type' => 'textarea', 'rules' => 'max:5000', 'help' => 'goals.effectiveness_help'],
            ],
        ];
    }

    protected function listQuery(): array
    {
        return ['select' => ", CONCAT(t.progress_percent, '%') AS progress_label"];
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        if ($data['end_date'] < $data['start_date']) {
            $v->addError('end_date', __('app.errors.date_order'));
        }
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if ($data['status'] === 'completed') {
            $data['progress_percent'] = 100;
        }
        return $data;
    }

    protected function rowEditable(array $row): bool
    {
        return $this->accessScope()->isUnrestricted() || (int) $row['owner_user_id'] === $this->auth()->id();
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/goals/$id";
    }

    private function editableGoal(int $id): array
    {
        $this->guardWritable();
        if (!can('work_plan.update')) {
            throw new HttpException(403);
        }
        return $this->findEditable($id);
    }

    public function show(Request $request, int $id): Response
    {
        $goal = $this->find($id);
        $documents = new DocumentService($this->db(), $this->auth(), App::root('storage/uploads'));
        return $this->view('goals/show', [
            'goal' => $goal + ['display_status' => GoalService::displayStatus($goal)],
            'meta' => $this->db()->one(
                'SELECT o.title AS objective_title, d.name AS domain_name, u.full_name AS owner_name
                   FROM annual_goals g JOIN plan_objectives o ON o.id = g.plan_objective_id
                   LEFT JOIN pedagogical_domains d ON d.id = g.domain_id LEFT JOIN users u ON u.id = g.owner_user_id
                  WHERE g.id = ?',
                [$id]
            ),
            'indicators' => $this->db()->all('SELECT * FROM goal_indicators WHERE goal_id = ? ORDER BY id', [$id]),
            'actions' => $this->db()->all(
                'SELECT a.*, u.full_name AS owner_name FROM goal_actions a LEFT JOIN users u ON u.id = a.owner_user_id
                  WHERE a.goal_id = ? ORDER BY a.due_date IS NULL, a.due_date, a.id',
                [$id]
            ),
            'updates' => $this->db()->all(
                'SELECT p.*, u.full_name FROM goal_progress_updates p LEFT JOIN users u ON u.id = p.created_by
                  WHERE p.goal_id = ? ORDER BY p.id DESC',
                [$id]
            ),
            'documents' => $documents->forEntity('goal', $id, $this->schoolId()),
            'users' => $this->fields()['owner_user_id']['options'],
            'statuses' => $this->fields()['status']['options'],
            'canEdit' => can('work_plan.update') && $this->rowEditable($goal) && !$this->year()['is_locked'],
            'userId' => $this->auth()->id(),
        ]);
    }

    public function storeIndicator(Request $request, int $id): Response
    {
        $this->editableGoal($id);
        $number = ['regex:/^-?\d{1,8}([.,]\d{1,2})?$/'];
        $v = Validator::make($request->post, [
            'name' => 'required|max:190', 'unit' => 'max:30',
            'baseline_value' => $number, 'target_value' => $number, 'current_value' => $number,
        ], 'goals.indicator_fields');
        if ($v->fails()) {
            return $this->invalid("/goals/$id", $v->errors(), $request->post);
        }
        $data = array_map(static fn ($value) => is_string($value) ? str_replace(',', '.', $value) : $value, $v->validated());
        $rowId = $this->db()->insert('goal_indicators', $data + ['school_id' => $this->schoolId(), 'goal_id' => $id]);
        $this->audit('create', 'goal_indicator', $rowId, null, $data);
        return $this->redirect("/goals/$id", 'app.created');
    }

    public function updateIndicator(Request $request, int $id, int $indicator): Response
    {
        $this->editableGoal($id);
        $row = $this->db()->one('SELECT * FROM goal_indicators WHERE id = ? AND goal_id = ?', [$indicator, $id]) ?? throw new HttpException(404);
        $v = Validator::make($request->post, ['current_value' => ['required', 'regex:/^-?\d{1,8}([.,]\d{1,2})?$/']], 'goals.indicator_fields');
        if ($v->fails()) {
            return $this->redirect("/goals/$id", null, $v->errors()['current_value'][0]);
        }
        $value = str_replace(',', '.', $v->validated()['current_value']);
        $this->db()->update('goal_indicators', ['current_value' => $value], ['id' => $indicator]);
        $this->audit('update', 'goal_indicator', $indicator, ['current_value' => $row['current_value']], ['current_value' => $value]);
        return $this->redirect("/goals/$id", 'app.updated');
    }

    public function destroyIndicator(Request $request, int $id, int $indicator): Response
    {
        $this->editableGoal($id);
        $deleted = $this->db()->run('DELETE FROM goal_indicators WHERE id = ? AND goal_id = ?', [$indicator, $id])->rowCount();
        if ($deleted === 0) {
            throw new HttpException(404);
        }
        $this->audit('delete', 'goal_indicator', $indicator);
        return $this->redirect("/goals/$id", 'app.deleted');
    }

    public function storeAction(Request $request, int $id): Response
    {
        $this->editableGoal($id);
        $users = $this->fields()['owner_user_id']['options'];
        $v = Validator::make($request->post, [
            'title' => 'required|max:190', 'owner_user_id' => 'in:' . implode(',', array_keys($users)),
            'start_date' => 'date', 'due_date' => 'date', 'resources' => 'max:1000',
            'budget' => ['regex:/^\d{1,8}([.,]\d{1,2})?$/'],
        ], 'goals.action_fields');
        $data = $v->validated();
        if (!$v->fails() && $data['start_date'] !== null && $data['due_date'] !== null && $data['due_date'] < $data['start_date']) {
            $v->addError('due_date', __('app.errors.date_order'));
        }
        if ($v->fails()) {
            return $this->invalid("/goals/$id", $v->errors(), $request->post);
        }
        $data['budget'] = $data['budget'] === null ? null : str_replace(',', '.', $data['budget']);
        $data['owner_user_id'] = $data['owner_user_id'] === null ? null : (int) $data['owner_user_id'];
        $actionId = $this->db()->transaction(function () use ($data, $id): int {
            $actionId = $this->db()->insert('goal_actions', $data + ['school_id' => $this->schoolId(), 'goal_id' => $id]);
            GoalService::recalculate($this->db(), $id);
            return $actionId;
        });
        $this->audit('create', 'goal_action', $actionId, null, $data);
        return $this->redirect("/goals/$id", 'app.created');
    }

    /** The owner of an action may mark it done even without being able to edit the goal. */
    public function toggleAction(Request $request, int $id, int $action): Response
    {
        $this->guardWritable();
        $goal = $this->find($id);
        $row = $this->db()->one('SELECT * FROM goal_actions WHERE id = ? AND goal_id = ?', [$action, $id]) ?? throw new HttpException(404);
        $mayEdit = can('work_plan.update') && ($this->rowEditable($goal) || (int) $row['owner_user_id'] === $this->auth()->id());
        if (!$mayEdit) {
            throw new HttpException(403);
        }
        $done = $row['status'] !== 'done';
        $this->db()->transaction(function () use ($done, $action, $id): void {
            $this->db()->update('goal_actions', [
                'status' => $done ? 'done' : 'open', 'completed_at' => $done ? date('Y-m-d H:i:s') : null,
            ], ['id' => $action]);
            GoalService::recalculate($this->db(), $id);
        });
        $this->audit('update', 'goal_action', $action, ['status' => $row['status']], ['status' => $done ? 'done' : 'open']);
        return $this->redirect("/goals/$id", 'app.updated');
    }

    public function destroyAction(Request $request, int $id, int $action): Response
    {
        $this->editableGoal($id);
        $deleted = $this->db()->run('DELETE FROM goal_actions WHERE id = ? AND goal_id = ?', [$action, $id])->rowCount();
        if ($deleted === 0) {
            throw new HttpException(404);
        }
        GoalService::recalculate($this->db(), $id);
        $this->audit('delete', 'goal_action', $action);
        return $this->redirect("/goals/$id", 'app.deleted');
    }

    /** A dated progress report. With actions defined, the percentage follows them and only status and note are taken. */
    public function storeUpdate(Request $request, int $id): Response
    {
        $goal = $this->editableGoal($id);
        $v = Validator::make($request->post, [
            'progress_percent' => 'required|int|min:0|max:100',
            'status' => 'required|in:' . implode(',', GoalService::STATUSES),
            'note' => 'max:2000',
        ], 'goals.update_fields');
        if ($v->fails()) {
            return $this->invalid("/goals/$id", $v->errors(), $request->post);
        }
        $data = $v->validated();
        $this->db()->transaction(function () use ($data, $id): void {
            $this->db()->update('annual_goals', [
                'progress_percent' => $data['status'] === 'completed' ? 100 : $data['progress_percent'],
                'status' => $data['status'], 'updated_by' => $this->auth()->id(),
            ], ['id' => $id]);
            if ($data['status'] !== 'completed') {
                GoalService::recalculate($this->db(), $id);
            }
            $this->db()->insert('goal_progress_updates', [
                'school_id' => $this->schoolId(), 'goal_id' => $id, 'status' => $data['status'], 'note' => $data['note'],
                'progress_percent' => (int) $this->db()->value('SELECT progress_percent FROM annual_goals WHERE id = ?', [$id]),
                'created_by' => $this->auth()->id(),
            ]);
        });
        $this->audit('update', 'goal', $id, ['status' => $goal['status'], 'progress_percent' => $goal['progress_percent']], $data);
        return $this->redirect("/goals/$id", 'app.updated');
    }
}
