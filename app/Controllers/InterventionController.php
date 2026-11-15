<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Personal intervention plans. A plan is always opened by a person — possibly
 * starting from a reviewed indication, never automatically.
 *
 * Visible to those who see the student's class in full, to the plan's owner,
 * and to anyone responsible for one of its actions.
 */
final class InterventionController extends CrudController
{
    public const STATUSES = ['active', 'paused', 'completed', 'cancelled'];
    public const RATINGS = ['improved', 'same', 'declined'];

    protected function definition(): array
    {
        $schoolId = $this->schoolId();
        $statuses = [];
        foreach (self::STATUSES as $status) {
            $statuses[$status] = __('interventions.statuses.' . $status);
        }
        return [
            'table' => 'student_interventions', 'entity' => 'intervention', 'path' => '/interventions', 'lang' => 'interventions',
            'module' => 'interventions', 'scope' => 'year', 'order' => "t.status = 'active' DESC, t.review_date IS NULL, t.review_date, t.id DESC",
            'softDelete' => true, 'userstamps' => true, 'show' => true, 'nameField' => 'difficulty_area',
            'listExtra' => ['student_name' => 'interventions.fields.student_id', 'class_name' => 'students.fields.class_id'],
            'filters' => ['status' => ['label' => 'interventions.fields.status', 'options' => $statuses, 'sql' => 't.status = ?']],
            'fields' => [
                'student_id' => ['type' => 'select', 'rules' => 'required', 'options' => fn () => $this->studentOptions()],
                'difficulty_area' => ['rules' => 'required|max:150', 'list' => true, 'attrs' => 'maxlength="150"', 'help' => 'interventions.area_help'],
                'subject_id' => ['type' => 'select', 'options' => fn () => $this->options(
                    'SELECT id, name AS label FROM subjects WHERE school_id = ? ORDER BY name',
                    [$schoolId]
                )],
                'situation_description' => ['type' => 'textarea', 'rules' => 'max:3000'],
                'baseline' => ['type' => 'textarea', 'rules' => 'max:2000'],
                'improvement_goal' => ['type' => 'textarea', 'rules' => 'max:2000'],
                'success_measure' => ['type' => 'textarea', 'rules' => 'max:2000'],
                'owner_user_id' => ['type' => 'select', 'rules' => 'required', 'list' => true, 'options' => fn () => $this->userOptions()],
                'start_date' => ['type' => 'date', 'rules' => 'required', 'list' => true],
                'review_date' => ['type' => 'date', 'list' => true],
                'status' => ['type' => 'select', 'rules' => 'required', 'options' => $statuses, 'list' => true, 'default' => 'active'],
                'recommendations' => ['type' => 'textarea', 'rules' => 'max:3000'],
                'risk_indication_id' => ['type' => 'hidden', 'rules' => 'int', 'virtual' => true],
            ],
        ];
    }

    /** @return array{0: string, 1: array} who may see a row of alias t */
    private function visibility(): array
    {
        [$condition, $params] = $this->accessScope()->fullClassCondition('e.class_id');
        $me = (int) $this->auth()->id();
        return [
            "(t.owner_user_id = ? OR EXISTS (SELECT 1 FROM intervention_actions ia WHERE ia.intervention_id = t.id AND ia.owner_user_id = ?)
              OR EXISTS (SELECT 1 FROM class_enrollments e JOIN classes c ON c.id = e.class_id
                          WHERE e.student_id = t.student_id AND e.left_on IS NULL AND c.academic_year_id = t.academic_year_id AND $condition))",
            [$me, $me, ...$params],
        ];
    }

    protected function listQuery(): array
    {
        $yearId = (int) ($this->auth()->year()['id'] ?? 0);
        [$where, $params] = $this->visibility();
        return [
            'select' => ", CONCAT(s.last_name, ' ', s.first_name) AS student_name, lc.name AS class_name",
            'join' => "JOIN students s ON s.id = t.student_id
                       LEFT JOIN class_enrollments le ON le.student_id = s.id AND le.left_on IS NULL
                            AND le.class_id IN (SELECT id FROM classes WHERE academic_year_id = $yearId)
                       LEFT JOIN classes lc ON lc.id = le.class_id",
            'where' => $where, 'params' => $params,
        ];
    }

    protected function rowVisible(array $row): bool
    {
        [$where, $params] = $this->visibility();
        return $this->db()->value("SELECT 1 FROM student_interventions t WHERE t.id = ? AND $where", [$row['id'], ...$params]) !== null;
    }

    /** Changing the plan itself: its owner, or someone who sees the student's whole class. */
    protected function rowEditable(array $row): bool
    {
        return (int) $row['owner_user_id'] === $this->auth()->id() || $this->accessScope()->canSeeStudentFully((int) $row['student_id']);
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        if ($data['review_date'] !== null && $data['review_date'] < $data['start_date']) {
            $v->addError('review_date', __('app.errors.date_order'));
        }
        if ($existing !== null && (int) $existing['student_id'] !== (int) $data['student_id']) {
            $v->addError('student_id', __('interventions.errors.student_fixed'));
        }
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        // A plan may be started from an indication that this user can see and that concerns the same student.
        $indicationId = $this->virtual['risk_indication_id'] ?? null;
        if ($existing === null && $indicationId !== null && $this->db()->value(
            'SELECT 1 FROM risk_indications WHERE id = ? AND school_id = ? AND student_id = ?',
            [$indicationId, $this->schoolId(), $data['student_id']]
        ) !== null) {
            $data['risk_indication_id'] = (int) $indicationId;
        }
        return $data;
    }

    protected function afterSave(int $id, ?array $existing): void
    {
        $row = $this->db()->one('SELECT owner_user_id, difficulty_area, risk_indication_id FROM student_interventions WHERE id = ?', [$id]);
        if ($existing === null) {
            if ($row['risk_indication_id'] !== null) {
                // Recorded as this person's decision on the indication.
                $this->db()->update('risk_indications', [
                    'review_status' => 'intervention', 'reviewed_by' => $this->auth()->id(), 'reviewed_at' => date('Y-m-d H:i:s'),
                ], ['id' => $row['risk_indication_id']]);
            }
            if ((int) $row['owner_user_id'] !== $this->auth()->id()) {
                \App\Services\Notifier::send((int) $row['owner_user_id'], $this->schoolId(), 'intervention', __('interventions.notify_owner'), null, "/interventions/$id");
            }
        }
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/interventions/$id";
    }

    public function create(Request $request): Response
    {
        $this->guardWritable();
        $values = [];
        // Opening from an indication pre-fills the student; the form is still filled in and saved by a person.
        $indication = $request->int('indication');
        if ($indication > 0) {
            $row = $this->db()->one(
                'SELECT ri.id, ri.student_id, rc.name FROM risk_indications ri JOIN risk_criteria rc ON rc.id = ri.criterion_id
                  WHERE ri.id = ? AND ri.school_id = ?',
                [$indication, $this->schoolId()]
            );
            if ($row !== null && isset($this->fields()['student_id']['options'][(int) $row['student_id']])) {
                $values = ['student_id' => (int) $row['student_id'], 'risk_indication_id' => (int) $row['id'], 'situation_description' => $row['name']];
            }
        }
        $fields = $this->fields();
        foreach ($values as $name => $value) {
            $fields[$name]['default'] = $value;
        }
        return $this->view('crud/form', ['def' => $this->def(), 'fields' => $fields, 'row' => null, 'values' => []]);
    }

    public function show(Request $request, int $id): Response
    {
        $row = $this->find($id);
        $this->audit('view', 'intervention', $id);
        $actions = $this->db()->all(
            'SELECT a.*, u.full_name AS owner_name FROM intervention_actions a LEFT JOIN users u ON u.id = a.owner_user_id
              WHERE a.intervention_id = ? ORDER BY a.due_date IS NULL, a.due_date, a.id',
            [$id]
        );
        $reviews = $this->db()->all(
            'SELECT r.*, u.full_name FROM intervention_reviews r LEFT JOIN users u ON u.id = r.created_by
              WHERE r.intervention_id = ? ORDER BY r.review_date DESC, r.id DESC',
            [$id]
        );

        // Timeline: start, completed actions and follow-up reviews, newest first.
        $timeline = [['date' => $row['start_date'], 'type' => 'start', 'text' => $row['difficulty_area'], 'by' => null]];
        foreach ($actions as $action) {
            if ($action['status'] === 'done') {
                $timeline[] = ['date' => substr((string) $action['completed_at'], 0, 10), 'type' => 'action', 'text' => $action['description'], 'by' => $action['owner_name']];
            }
        }
        foreach ($reviews as $review) {
            $timeline[] = ['date' => $review['review_date'], 'type' => 'review', 'text' => $review['findings'], 'by' => $review['full_name'], 'rating' => $review['progress_rating']];
        }
        usort($timeline, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return $this->view('interventions/show', [
            'row' => $row, 'actions' => $actions, 'reviews' => $reviews, 'timeline' => $timeline,
            'meta' => $this->db()->one(
                "SELECT CONCAT(s.last_name, ' ', s.first_name) AS student_name, sj.name AS subject_name, u.full_name AS owner_name
                   FROM student_interventions t JOIN students s ON s.id = t.student_id
                   LEFT JOIN subjects sj ON sj.id = t.subject_id LEFT JOIN users u ON u.id = t.owner_user_id WHERE t.id = ?",
                [$id]
            ),
            'users' => $this->userOptions(),
            'canEdit' => can('interventions.update') && $this->rowEditable($row) && !$this->year()['is_locked'],
            'userId' => $this->auth()->id(),
        ]);
    }

    private function editable(int $id): array
    {
        $this->guardWritable();
        return $this->findEditable($id);
    }

    public function storeAction(Request $request, int $id): Response
    {
        $this->editable($id);
        $users = $this->userOptions();
        $v = Validator::make($request->post, [
            'description' => 'required|max:255', 'owner_user_id' => 'in:' . implode(',', array_keys($users)), 'due_date' => 'date',
        ], 'interventions.action_fields');
        if ($v->fails()) {
            return $this->invalid("/interventions/$id", $v->errors(), $request->post);
        }
        $data = $v->validated();
        $data['owner_user_id'] = $data['owner_user_id'] === null ? null : (int) $data['owner_user_id'];
        $actionId = $this->db()->insert('intervention_actions', $data + ['school_id' => $this->schoolId(), 'intervention_id' => $id]);
        if ($data['owner_user_id'] !== null && $data['owner_user_id'] !== $this->auth()->id()) {
            \App\Services\Notifier::send($data['owner_user_id'], $this->schoolId(), 'intervention', __('interventions.notify_action'), $data['description'], "/interventions/$id");
        }
        $this->audit('create', 'intervention_action', $actionId, null, $data);
        return $this->redirect("/interventions/$id", 'app.created');
    }

    /** Whoever is responsible for an action may mark it done. */
    public function toggleAction(Request $request, int $id, int $action): Response
    {
        $this->guardWritable();
        $row = $this->find($id);
        $item = $this->db()->one('SELECT * FROM intervention_actions WHERE id = ? AND intervention_id = ?', [$action, $id]) ?? throw new HttpException(404);
        if (!$this->rowEditable($row) && (int) $item['owner_user_id'] !== $this->auth()->id()) {
            throw new HttpException(403);
        }
        $done = $item['status'] !== 'done';
        $this->db()->update('intervention_actions', ['status' => $done ? 'done' : 'open', 'completed_at' => $done ? date('Y-m-d H:i:s') : null], ['id' => $action]);
        $this->audit('update', 'intervention_action', $action, ['status' => $item['status']], ['status' => $done ? 'done' : 'open']);
        return $this->redirect("/interventions/$id", 'app.updated');
    }

    public function destroyAction(Request $request, int $id, int $action): Response
    {
        $this->editable($id);
        if ($this->db()->run('DELETE FROM intervention_actions WHERE id = ? AND intervention_id = ?', [$action, $id])->rowCount() === 0) {
            throw new HttpException(404);
        }
        $this->audit('delete', 'intervention_action', $action);
        return $this->redirect("/interventions/$id", 'app.deleted');
    }

    public function storeReview(Request $request, int $id): Response
    {
        $this->editable($id);
        $v = Validator::make($request->post, [
            'review_date' => 'required|date', 'findings' => 'required|max:3000',
            'progress_rating' => 'required|in:' . implode(',', self::RATINGS), 'next_steps' => 'max:2000', 'next_review_date' => 'date',
        ], 'interventions.review_fields');
        $data = $v->validated();
        if (!$v->fails() && $data['review_date'] > date('Y-m-d')) {
            $v->addError('review_date', __('interventions.errors.review_future'));
        }
        if ($v->fails()) {
            return $this->invalid("/interventions/$id", $v->errors(), $request->post);
        }
        $next = $data['next_review_date'];
        unset($data['next_review_date']);
        $reviewId = $this->db()->transaction(function () use ($data, $id, $next): int {
            $reviewId = $this->db()->insert('intervention_reviews', $data + [
                'school_id' => $this->schoolId(), 'intervention_id' => $id, 'created_by' => $this->auth()->id(),
            ]);
            $this->db()->update('student_interventions', ['review_date' => $next], ['id' => $id]);
            return $reviewId;
        });
        $this->audit('create', 'intervention_review', $reviewId, null, ['progress_rating' => $data['progress_rating']]);
        return $this->redirect("/interventions/$id", 'app.created');
    }
}
