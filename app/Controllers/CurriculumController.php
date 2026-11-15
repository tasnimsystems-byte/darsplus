<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Curriculum plans: one plan per subject and grade level (or one class), built
 * from units and topics. What was actually taught is recorded per teaching
 * assignment, which is what makes planned-versus-actual comparable between
 * parallel classes.
 */
final class CurriculumController extends CrudController
{
    public const PROGRESS = ['not_started', 'in_progress', 'done', 'skipped'];

    protected function definition(): array
    {
        $schoolId = $this->schoolId();
        $yearId = (int) ($this->auth()->year()['id'] ?? 0);
        $subjects = fn () => $this->options('SELECT id, name AS label FROM subjects WHERE school_id = ? ORDER BY name', [$schoolId]);
        $levels = fn () => $this->options('SELECT id, name AS label FROM grade_levels WHERE school_id = ? ORDER BY level_number', [$schoolId]);
        return [
            'table' => 'curriculum_plans', 'entity' => 'curriculum_plan', 'path' => '/curriculum', 'lang' => 'curriculum',
            'module' => 'curriculum', 'scope' => 'year', 'order' => 't.title, t.id', 'softDelete' => true, 'userstamps' => true,
            'show' => true, 'nameField' => 'title', 'search' => ['t.title'],
            'filters' => [
                'subject_id' => ['label' => 'curriculum.fields.subject_id', 'options' => $subjects(), 'sql' => 't.subject_id = ?'],
                'grade_level_id' => ['label' => 'curriculum.fields.grade_level_id', 'options' => $levels(), 'sql' => 't.grade_level_id = ?'],
            ],
            'listExtra' => ['status_label' => 'curriculum.fields.status'],
            'fields' => [
                'title' => ['rules' => 'required|max:190', 'list' => true, 'attrs' => 'maxlength="190"'],
                'subject_id' => ['type' => 'select', 'rules' => 'required', 'options' => $subjects, 'list' => true],
                'grade_level_id' => ['type' => 'select', 'rules' => 'required', 'options' => $levels, 'list' => true],
                'class_id' => ['type' => 'select', 'help' => 'curriculum.class_help', 'options' => fn () => $this->options(
                    'SELECT id, name AS label FROM classes WHERE school_id = ? AND academic_year_id = ? ORDER BY name',
                    [$schoolId, $yearId]
                )],
                'owner_teacher_id' => ['type' => 'select', 'list' => true, 'help' => 'curriculum.owner_help', 'options' => fn () => $this->options(
                    "SELECT id, CONCAT(last_name, ' ', first_name) AS label FROM teachers
                      WHERE school_id = ? AND deleted_at IS NULL ORDER BY last_name, first_name",
                    [$schoolId]
                )],
            ],
        ];
    }

    /** A plan concerns a user when one of the assignments they may see teaches that subject in that level/class. */
    private function visibility(string $plan): array
    {
        [$condition, $params] = $this->accessScope()->assignmentCondition('ta');
        return ["EXISTS (SELECT 1 FROM teaching_assignments ta JOIN classes c ON c.id = ta.class_id
                  WHERE ta.academic_year_id = $plan.academic_year_id AND ta.subject_id = $plan.subject_id
                    AND c.grade_level_id = $plan.grade_level_id AND ($plan.class_id IS NULL OR $plan.class_id = ta.class_id)
                    AND $condition)", $params];
    }

    protected function listQuery(): array
    {
        $select = ", CASE t.status WHEN 'approved' THEN " . $this->db()->pdo()->quote(__('curriculum.statuses.approved'))
            . ' ELSE ' . $this->db()->pdo()->quote(__('curriculum.statuses.draft')) . ' END AS status_label';
        if ($this->accessScope()->isUnrestricted()) {
            return ['select' => $select];
        }
        [$where, $params] = $this->visibility('t');
        return ['select' => $select, 'where' => $where, 'params' => $params];
    }

    protected function rowVisible(array $row): bool
    {
        if ($this->accessScope()->isUnrestricted()) {
            return true;
        }
        [$where, $params] = $this->visibility('p');
        return $this->db()->value("SELECT 1 FROM curriculum_plans p WHERE p.id = ? AND $where", [$row['id'], ...$params]) !== null;
    }

    /** The structure of a plan is edited by its owner or by someone who sees the whole school. */
    protected function rowEditable(array $row): bool
    {
        return $this->accessScope()->isUnrestricted() || ($row['owner_teacher_id'] !== null && $this->db()->value(
            'SELECT 1 FROM teachers WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
            [$row['owner_teacher_id'], $this->auth()->id()]
        ) !== null);
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        if ($data['class_id'] !== null && $this->db()->value(
            'SELECT 1 FROM classes WHERE id = ? AND grade_level_id = ?',
            [$data['class_id'], $data['grade_level_id']]
        ) === null) {
            $v->addError('class_id', __('curriculum.errors.class_level'));
        }
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/curriculum/$id";
    }

    private function editablePlan(int $id): array
    {
        $this->guardWritable();
        return $this->findEditable($id);
    }

    /** Teaching assignments this plan applies to, restricted to those the user may see. */
    private function assignments(array $plan): array
    {
        [$condition, $params] = $this->accessScope()->assignmentCondition('ta');
        $rows = $this->db()->all(
            "SELECT ta.id, ta.group_name, c.name AS class_name, CONCAT(t.last_name, ' ', t.first_name) AS teacher_name
               FROM teaching_assignments ta JOIN classes c ON c.id = ta.class_id JOIN teachers t ON t.id = ta.teacher_id
              WHERE ta.school_id = ? AND ta.academic_year_id = ? AND ta.subject_id = ? AND c.grade_level_id = ?
                AND (? IS NULL OR ta.class_id = ?) AND $condition
              ORDER BY c.name, t.last_name",
            [$this->schoolId(), $plan['academic_year_id'], $plan['subject_id'], $plan['grade_level_id'], $plan['class_id'], $plan['class_id'], ...$params]
        );
        foreach ($rows as &$row) {
            $row['editable'] = $this->accessScope()->canEditAssignment((int) $row['id']);
        }
        return $rows;
    }

    private function topics(int $planId): array
    {
        return $this->db()->all(
            'SELECT tp.*, u.title AS unit_title, u.sort_order AS unit_order
               FROM curriculum_topics tp JOIN curriculum_units u ON u.id = tp.unit_id
              WHERE u.plan_id = ?
              ORDER BY u.sort_order, u.id, COALESCE(tp.parent_topic_id, tp.id), tp.parent_topic_id IS NOT NULL, tp.sort_order, tp.id',
            [$planId]
        );
    }

    public function show(Request $request, int $id): Response
    {
        $plan = $this->find($id);
        $topics = $this->topics($id);
        $assignments = $this->assignments($plan);
        $today = date('Y-m-d');

        // Planned versus actual, per teaching assignment.
        $progress = [];
        foreach ($this->db()->all(
            'SELECT cp.teaching_assignment_id, cp.topic_id, cp.status, cp.actual_hours
               FROM curriculum_progress cp JOIN curriculum_topics tp ON tp.id = cp.topic_id JOIN curriculum_units u ON u.id = tp.unit_id
              WHERE u.plan_id = ?',
            [$id]
        ) as $row) {
            $progress[(int) $row['teaching_assignment_id']][(int) $row['topic_id']] = $row;
        }
        $plannedHours = array_sum(array_map(static fn ($t) => (float) $t['planned_hours'], $topics));
        foreach ($assignments as &$assignment) {
            $mine = $progress[(int) $assignment['id']] ?? [];
            $done = $due = $doneDue = 0;
            foreach ($topics as $topic) {
                $isDone = ($mine[(int) $topic['id']]['status'] ?? '') === 'done';
                $isDue = $topic['planned_week_start'] !== null && $topic['planned_week_start'] <= $today;
                $done += (int) $isDone;
                $due += (int) $isDue;
                $doneDue += (int) ($isDone && $isDue);
            }
            $assignment += [
                'done' => $done, 'due' => $due, 'behind' => $due - $doneDue,
                'percent' => $topics === [] ? 0 : (int) round($done / count($topics) * 100),
                'actual_hours' => array_sum(array_map(static fn ($p) => (float) $p['actual_hours'], $mine)),
            ];
        }

        $view = in_array($request->str('view'), ['month', 'week'], true) ? $request->str('view') : 'outline';
        return $this->view('curriculum/show', [
            'plan' => $plan,
            'meta' => $this->db()->one(
                "SELECT s.name AS subject_name, gl.name AS level_name, c.name AS class_name, CONCAT(t.last_name, ' ', t.first_name) AS owner_name
                   FROM curriculum_plans p JOIN subjects s ON s.id = p.subject_id JOIN grade_levels gl ON gl.id = p.grade_level_id
                   LEFT JOIN classes c ON c.id = p.class_id LEFT JOIN teachers t ON t.id = p.owner_teacher_id
                  WHERE p.id = ?",
                [$id]
            ),
            'units' => $this->db()->all(
                'SELECT u.*, tm.name AS term_name FROM curriculum_units u LEFT JOIN academic_terms tm ON tm.id = u.term_id
                  WHERE u.plan_id = ? ORDER BY u.sort_order, u.id',
                [$id]
            ),
            'topics' => $topics, 'assignments' => $assignments, 'plannedHours' => $plannedHours, 'view' => $view,
            'terms' => $this->options('SELECT id, name AS label FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order', [$plan['academic_year_id']]),
            'canEdit' => can('curriculum.update') && $this->rowEditable($plan) && !$this->year()['is_locked'],
        ]);
    }

    public function approve(Request $request, int $id): Response
    {
        $this->guardWritable();
        $plan = $this->find($id);
        $status = $plan['status'] === 'approved' ? 'draft' : 'approved';
        $this->db()->update('curriculum_plans', ['status' => $status], ['id' => $id]);
        $this->audit($status === 'approved' ? 'approve' : 'unapprove', 'curriculum_plan', $id);
        return $this->redirect("/curriculum/$id", 'app.updated');
    }

    public function storeUnit(Request $request, int $id): Response
    {
        $plan = $this->editablePlan($id);
        $terms = $this->db()->column('SELECT id FROM academic_terms WHERE academic_year_id = ?', [$plan['academic_year_id']]);
        $v = Validator::make($request->post, ['title' => 'required|max:190', 'term_id' => 'in:' . implode(',', $terms)], 'curriculum.unit_fields');
        if ($v->fails()) {
            return $this->invalid("/curriculum/$id", $v->errors(), $request->post);
        }
        $data = $v->validated();
        $unitId = $this->db()->insert('curriculum_units', [
            'school_id' => $this->schoolId(), 'plan_id' => $id, 'title' => $data['title'],
            'term_id' => $data['term_id'] === null ? null : (int) $data['term_id'],
            'sort_order' => 1 + (int) $this->db()->value('SELECT COALESCE(MAX(sort_order), 0) FROM curriculum_units WHERE plan_id = ?', [$id]),
        ]);
        $this->audit('create', 'curriculum_unit', $unitId, null, $data);
        return $this->redirect("/curriculum/$id", 'app.created');
    }

    public function destroyUnit(Request $request, int $id, int $unit): Response
    {
        $this->editablePlan($id);
        return $this->deleteChild('curriculum_units', 'curriculum_unit', 'id = ? AND plan_id = ?', [$unit, $id], $id);
    }

    private function topicRules(): array
    {
        return [
            'title' => 'required|max:190', 'unit_id' => 'required|int', 'parent_topic_id' => 'int',
            'planned_week_start' => 'date', 'planned_hours' => ['regex:/^\d{1,2}([.,]\d)?$/'],
            'learning_objectives' => 'max:2000', 'skills' => 'max:2000', 'teaching_methods' => 'max:2000', 'assessment_methods' => 'max:2000',
        ];
    }

    /** @return array{0: array, 1: Validator} */
    private function topicInput(Request $request, int $planId, ?int $topicId): array
    {
        $v = Validator::make($request->post, $this->topicRules(), 'curriculum.topic_fields');
        $data = $v->validated();
        if (!$v->fails()) {
            if ($this->db()->value('SELECT 1 FROM curriculum_units WHERE id = ? AND plan_id = ?', [$data['unit_id'], $planId]) === null) {
                $v->addError('unit_id', __('validation.in', ['field' => __('curriculum.topic_fields.unit_id')]));
            }
            // One level of sub-topics: the parent must be a top-level topic of the same unit.
            if ($data['parent_topic_id'] !== null && ($data['parent_topic_id'] === $topicId || $this->db()->value(
                'SELECT 1 FROM curriculum_topics WHERE id = ? AND unit_id = ? AND parent_topic_id IS NULL',
                [$data['parent_topic_id'], $data['unit_id']]
            ) === null)) {
                $v->addError('parent_topic_id', __('validation.in', ['field' => __('curriculum.topic_fields.parent_topic_id')]));
            }
            $data['planned_hours'] = $data['planned_hours'] === null ? null : str_replace(',', '.', $data['planned_hours']);
        }
        return [$data, $v];
    }

    public function storeTopic(Request $request, int $id): Response
    {
        $this->editablePlan($id);
        [$data, $v] = $this->topicInput($request, $id, null);
        if ($v->fails()) {
            return $this->invalid("/curriculum/$id", $v->errors(), $request->post);
        }
        $topicId = $this->db()->insert('curriculum_topics', $data + [
            'school_id' => $this->schoolId(),
            'sort_order' => 1 + (int) $this->db()->value('SELECT COALESCE(MAX(sort_order), 0) FROM curriculum_topics WHERE unit_id = ?', [$data['unit_id']]),
        ]);
        $this->audit('create', 'curriculum_topic', $topicId, null, ['title' => $data['title']]);
        return $this->redirect("/curriculum/$id", 'app.created');
    }

    private function topic(int $planId, int $topicId): array
    {
        return $this->db()->one(
            'SELECT tp.* FROM curriculum_topics tp JOIN curriculum_units u ON u.id = tp.unit_id WHERE tp.id = ? AND u.plan_id = ?',
            [$topicId, $planId]
        ) ?? throw new HttpException(404);
    }

    public function editTopic(Request $request, int $id, int $topic): Response
    {
        $plan = $this->editablePlan($id);
        return $this->view('curriculum/topic', [
            'plan' => $plan, 'topic' => $this->topic($id, $topic),
            'units' => $this->options('SELECT id, title AS label FROM curriculum_units WHERE plan_id = ? ORDER BY sort_order, id', [$id]),
            'parents' => $this->options(
                'SELECT tp.id, tp.title AS label FROM curriculum_topics tp JOIN curriculum_units u ON u.id = tp.unit_id
                  WHERE u.plan_id = ? AND tp.parent_topic_id IS NULL AND tp.id <> ? ORDER BY tp.sort_order, tp.id',
                [$id, $topic]
            ),
        ]);
    }

    public function updateTopic(Request $request, int $id, int $topic): Response
    {
        $this->editablePlan($id);
        $this->topic($id, $topic);
        [$data, $v] = $this->topicInput($request, $id, $topic);
        if ($v->fails()) {
            return $this->invalid("/curriculum/$id/topics/$topic/edit", $v->errors(), $request->post);
        }
        $this->db()->update('curriculum_topics', $data, ['id' => $topic]);
        $this->audit('update', 'curriculum_topic', $topic, null, ['title' => $data['title']]);
        return $this->redirect("/curriculum/$id", 'app.updated');
    }

    public function destroyTopic(Request $request, int $id, int $topic): Response
    {
        $this->editablePlan($id);
        $this->topic($id, $topic);
        return $this->deleteChild('curriculum_topics', 'curriculum_topic', 'id = ?', [$topic], $id);
    }

    private function deleteChild(string $table, string $entity, string $where, array $params, int $planId): Response
    {
        try {
            $deleted = $this->db()->run("DELETE FROM `$table` WHERE $where", $params)->rowCount();
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirect("/curriculum/$planId", null, 'app.errors.in_use');
            }
            throw $e;
        }
        if ($deleted === 0) {
            throw new HttpException(404);
        }
        $this->audit('delete', $entity, (int) $params[0]);
        return $this->redirect("/curriculum/$planId", 'app.deleted');
    }

    // ---- Actual teaching, per assignment -------------------------------------------

    private function assignment(array $plan, int $assignmentId): array
    {
        foreach ($this->assignments($plan) as $assignment) {
            if ((int) $assignment['id'] === $assignmentId) {
                return $assignment;
            }
        }
        throw new HttpException(404);
    }

    public function progress(Request $request, int $id, int $assignment): Response
    {
        $plan = $this->find($id);
        $row = $this->assignment($plan, $assignment);
        $progress = [];
        foreach ($this->db()->all('SELECT * FROM curriculum_progress WHERE teaching_assignment_id = ?', [$assignment]) as $p) {
            $progress[(int) $p['topic_id']] = $p;
        }
        return $this->view('curriculum/progress', [
            'plan' => $plan, 'assignment' => $row, 'topics' => $this->topics($id), 'progress' => $progress,
            'canEdit' => can('curriculum.update') && $row['editable'] && !$this->year()['is_locked'],
        ]);
    }

    public function saveProgress(Request $request, int $id, int $assignment): Response
    {
        $this->guardWritable();
        $plan = $this->find($id);
        if (!$this->assignment($plan, $assignment)['editable']) {
            throw new HttpException(403);
        }
        $input = is_array($request->post['topics'] ?? null) ? $request->post['topics'] : [];
        $errors = 0;
        $this->db()->transaction(function () use ($id, $assignment, $input, &$errors): void {
            foreach ($this->topics($id) as $topic) {
                $row = $input[$topic['id']] ?? null;
                if (!is_array($row)) {
                    continue;
                }
                $v = Validator::make($row, [
                    'status' => 'required|in:' . implode(',', self::PROGRESS), 'actual_date' => 'date',
                    'actual_hours' => ['regex:/^\d{1,2}([.,]\d)?$/'], 'note' => 'max:500',
                ], 'curriculum.progress_fields');
                if ($v->fails()) {
                    $errors++;
                    continue;
                }
                $data = $v->validated();
                $data['actual_hours'] = $data['actual_hours'] === null ? null : str_replace(',', '.', $data['actual_hours']);
                $this->db()->run(
                    'INSERT INTO curriculum_progress (school_id, topic_id, teaching_assignment_id, status, actual_date, actual_hours, note, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), actual_date = VALUES(actual_date),
                        actual_hours = VALUES(actual_hours), note = VALUES(note), updated_by = VALUES(updated_by)',
                    [$this->schoolId(), (int) $topic['id'], $assignment, $data['status'], $data['actual_date'], $data['actual_hours'], $data['note'], $this->auth()->id()]
                );
            }
        });
        $this->audit('update', 'curriculum_progress', $assignment, null, ['plan_id' => $id]);
        return $this->redirect("/curriculum/$id/progress/$assignment", $errors === 0 ? 'app.updated' : null, $errors === 0 ? null : 'curriculum.errors.some_rows');
    }
}
