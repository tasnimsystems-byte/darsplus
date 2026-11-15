<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Assessment events and their results.
 *
 * Who sees what: the teacher of the assignment; the homeroom teacher and anyone
 * whose scope covers the whole class; a coordinator of the subject; and users
 * who see the whole school. Drafts are visible only to those who may edit them.
 * Editing (the event and its scores) is for the assignment's own teacher or a
 * user who sees the whole school.
 */
final class AssessmentController extends CrudController
{
    public const RESULT_STATUSES = ['graded', 'absent', 'exempt', 'pending'];

    protected function definition(): array
    {
        $schoolId = $this->schoolId();
        $yearId = (int) ($this->auth()->year()['id'] ?? 0);
        $decimal = static fn (int $digits): array => ['required', "regex:/^\\d{1,$digits}([.,]\\d{1,2})?$/"];
        return [
            'table' => 'assessments', 'entity' => 'assessment', 'path' => '/assessments', 'lang' => 'assessments',
            'module' => 'assessments', 'scope' => 'year', 'order' => 't.assessment_date DESC, t.id DESC',
            'softDelete' => true, 'userstamps' => true, 'show' => true, 'nameField' => 'title', 'search' => ['t.title'],
            'listExtra' => ['assignment_label' => 'assessments.assignment', 'status_label' => 'assessments.fields.status', 'graded_count' => 'assessments.graded_count'],
            'filters' => [
                'term_id' => ['label' => 'assessments.fields.term_id', 'sql' => 't.term_id = ?', 'options' => $this->options(
                    'SELECT id, name AS label FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order',
                    [$yearId]
                )],
                'subject_id' => ['label' => 'assignments.fields.subject_id', 'sql' => 'ta.subject_id = ?', 'options' => $this->options(
                    'SELECT id, name AS label FROM subjects WHERE school_id = ? ORDER BY name',
                    [$schoolId]
                )],
            ],
            'fields' => [
                'title' => ['rules' => 'required|max:150', 'list' => true, 'attrs' => 'maxlength="150"'],
                // Only assignments the user may record for are offered.
                'teaching_assignment_id' => ['type' => 'select', 'rules' => 'required',
                    'options' => fn () => $this->assignmentOptions(true)],
                'term_id' => ['type' => 'select', 'rules' => 'required', 'list' => true, 'options' => fn () => $this->options(
                    'SELECT id, name AS label FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order',
                    [$yearId]
                )],
                'type_id' => ['type' => 'select', 'rules' => 'required', 'list' => true, 'options' => fn () => $this->options(
                    'SELECT id, name AS label FROM assessment_types WHERE school_id = ? ORDER BY id',
                    [$schoolId]
                )],
                'assessment_date' => ['type' => 'date', 'rules' => 'required', 'list' => true],
                'max_score' => ['rules' => $decimal(4), 'default' => '100', 'attrs' => 'inputmode="decimal" dir="ltr" maxlength="7"'],
                'weight' => ['rules' => $decimal(2), 'default' => '1', 'help' => 'assessments.weight_help', 'attrs' => 'inputmode="decimal" dir="ltr" maxlength="5"'],
            ],
        ];
    }

    private function assignmentOptions(bool $editableOnly): array
    {
        $scope = $this->accessScope();
        [$condition, $params] = $scope->assignmentCondition('ta');
        $options = [];
        foreach ($this->db()->all(
            "SELECT ta.id, CONCAT(c.name, ' · ', s.name, ' · ', t.last_name, ' ', t.first_name,
                           CASE WHEN ta.group_name <> '' THEN CONCAT(' (', ta.group_name, ')') ELSE '' END) AS label
               FROM teaching_assignments ta
               JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id JOIN teachers t ON t.id = ta.teacher_id
              WHERE ta.school_id = ? AND ta.academic_year_id = ? AND $condition
              ORDER BY c.name, s.name",
            [$this->schoolId(), (int) ($this->auth()->year()['id'] ?? 0), ...$params]
        ) as $row) {
            if (!$editableOnly || $scope->canEditAssignment((int) $row['id'])) {
                $options[(int) $row['id']] = $row['label'];
            }
        }
        return $options;
    }

    protected function listQuery(): array
    {
        $scope = $this->accessScope();
        [$condition, $params] = $scope->assignmentCondition('ta');
        $draft = '1 = 1';
        if (!$scope->isUnrestricted()) {
            // Drafts of colleagues stay private until published.
            $own = array_keys($this->assignmentOptions(true));
            $draft = "(t.status = 'published'" . ($own === [] ? '' : ' OR ta.id IN (' . implode(',', array_fill(0, count($own), '?')) . ')') . ')';
            $params = [...$params, ...$own];
        }
        $pdo = $this->db()->pdo();
        return [
            'select' => ", CONCAT(lc.name, ' · ', lsj.name) AS assignment_label, CASE t.status WHEN 'published' THEN " . $pdo->quote(__('assessments.statuses.published'))
                . ' ELSE ' . $pdo->quote(__('assessments.statuses.draft')) . " END AS status_label,
                (SELECT COUNT(*) FROM assessment_results r WHERE r.assessment_id = t.id AND r.result_status = 'graded') AS graded_count",
            'join' => 'JOIN teaching_assignments ta ON ta.id = t.teaching_assignment_id
                       JOIN classes lc ON lc.id = ta.class_id JOIN subjects lsj ON lsj.id = ta.subject_id',
            'where' => "$condition AND $draft",
            'params' => $params,
        ];
    }

    protected function rowVisible(array $row): bool
    {
        $scope = $this->accessScope();
        if ($scope->canEditAssignment((int) $row['teaching_assignment_id'])) {
            return true;
        }
        [$condition, $params] = $scope->assignmentCondition('ta');
        return $row['status'] === 'published' && $this->db()->value(
            "SELECT 1 FROM teaching_assignments ta WHERE ta.id = ? AND $condition",
            [$row['teaching_assignment_id'], ...$params]
        ) !== null;
    }

    protected function rowEditable(array $row): bool
    {
        return $this->accessScope()->canEditAssignment((int) $row['teaching_assignment_id']);
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        $year = $this->year();
        if ($data['assessment_date'] < $year['start_date'] || $data['assessment_date'] > $year['end_date']) {
            $v->addError('assessment_date', __('assessments.errors.date_outside'));
        }
        if ((float) str_replace(',', '.', $data['max_score']) <= 0) {
            $v->addError('max_score', __('assessments.errors.max_positive'));
        }
        // Lowering the maximum below a score that was already entered would corrupt the results.
        if ($existing !== null && (float) str_replace(',', '.', $data['max_score']) < (float) $this->db()->value(
            'SELECT COALESCE(MAX(score), 0) FROM assessment_results WHERE assessment_id = ?',
            [$existing['id']]
        )) {
            $v->addError('max_score', __('assessments.errors.max_below_scores'));
        }
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        $data['max_score'] = str_replace(',', '.', $data['max_score']);
        $data['weight'] = str_replace(',', '.', $data['weight']);
        return $data;
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/assessments/$id";
    }

    /** Students currently in the class, plus anyone who already has a result here. */
    private function roster(array $assessment): array
    {
        return $this->db()->all(
            'SELECT s.id, s.first_name, s.last_name, s.student_code, r.score, r.result_status, r.verbal_evaluation, r.comment
               FROM students s
               LEFT JOIN assessment_results r ON r.student_id = s.id AND r.assessment_id = ?
              WHERE s.school_id = ? AND s.deleted_at IS NULL AND (
                    r.id IS NOT NULL OR s.id IN (
                        SELECT e.student_id FROM class_enrollments e JOIN teaching_assignments ta ON ta.class_id = e.class_id
                         WHERE ta.id = ? AND e.left_on IS NULL))
              ORDER BY s.last_name, s.first_name, s.id',
            [$assessment['id'], $this->schoolId(), $assessment['teaching_assignment_id']]
        );
    }

    public function show(Request $request, int $id): Response
    {
        $assessment = $this->find($id);
        $roster = $this->roster($assessment);
        $meta = $this->db()->one(
            "SELECT c.id AS class_id, c.name AS class_name, s.name AS subject_name, tm.name AS term_name, ty.name AS type_name, ty.is_numeric,
                    CONCAT(t.last_name, ' ', t.first_name) AS teacher_name
               FROM assessments a
               JOIN teaching_assignments ta ON ta.id = a.teaching_assignment_id
               JOIN classes c ON c.id = ta.class_id JOIN subjects s ON s.id = ta.subject_id JOIN teachers t ON t.id = ta.teacher_id
               JOIN academic_terms tm ON tm.id = a.term_id JOIN assessment_types ty ON ty.id = a.type_id
              WHERE a.id = ?",
            [$id]
        );

        $scores = [];
        foreach ($roster as $row) {
            if ($row['result_status'] === 'graded' && $row['score'] !== null) {
                $scores[] = (float) $row['score'] / (float) $assessment['max_score'] * 100;
            }
        }
        sort($scores);
        $count = count($scores);
        $bands = array_fill(0, 4, 0);
        foreach ($scores as $score) {
            $bands[$score < 55 ? 0 : ($score < 70 ? 1 : ($score < 85 ? 2 : 3))]++;
        }
        return $this->view('assessments/show', [
            'assessment' => $assessment, 'meta' => $meta, 'roster' => $roster,
            'stats' => $count === 0 ? null : [
                'count' => $count, 'average' => round(array_sum($scores) / $count, 1),
                'median' => round($count % 2 ? $scores[intdiv($count, 2)] : ($scores[$count / 2 - 1] + $scores[$count / 2]) / 2, 1),
                'min' => round($scores[0], 1), 'max' => round($scores[$count - 1], 1), 'bands' => $bands,
            ],
            'canEdit' => can('assessments.update') && $this->rowEditable($assessment) && !$this->year()['is_locked'],
        ]);
    }

    public function saveResults(Request $request, int $id): Response
    {
        $this->guardWritable();
        $assessment = $this->findEditable($id);
        $numeric = (bool) $this->db()->value('SELECT is_numeric FROM assessment_types WHERE id = ?', [$assessment['type_id']]);
        $max = (float) $assessment['max_score'];
        $input = is_array($request->post['results'] ?? null) ? $request->post['results'] : [];
        $errors = [];
        $changed = 0;

        $this->db()->transaction(function () use ($assessment, $numeric, $max, $input, &$errors, &$changed): void {
            foreach ($this->roster($assessment) as $student) {
                $row = $input[$student['id']] ?? null;
                if (!is_array($row)) {
                    continue;
                }
                $scalar = static fn (string $key): string => is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
                $status = $scalar('status');
                $rawScore = str_replace(',', '.', $scalar('score'));
                $verbal = mb_substr($scalar('verbal'), 0, 255);
                $comment = mb_substr($scalar('comment'), 0, 500);
                if (!in_array($status, self::RESULT_STATUSES, true)) {
                    $status = $rawScore !== '' || $verbal !== '' ? 'graded' : 'pending';
                }

                $score = null;
                if ($status === 'graded') {
                    if ($numeric) {
                        if ($rawScore === '') {
                            $status = 'pending';
                        } elseif (!preg_match('/^\d{1,4}(\.\d)?$/', $rawScore) || (float) $rawScore > $max) {
                            $errors[] = $student['last_name'] . ' ' . $student['first_name'];
                            continue;
                        } else {
                            $score = $rawScore;
                        }
                    } elseif ($verbal === '') {
                        $status = 'pending';
                    }
                }
                if ($status === 'pending' && $student['result_status'] === null && $comment === '') {
                    continue; // nothing entered for this student
                }
                $before = [$student['score'] === null ? null : (float) $student['score'], $student['result_status'], $student['verbal_evaluation'], $student['comment']];
                $after = [$score === null ? null : (float) $score, $status, $verbal === '' ? null : $verbal, $comment === '' ? null : $comment];
                if ($before === $after) {
                    continue;
                }
                $this->db()->run(
                    'INSERT INTO assessment_results (school_id, assessment_id, student_id, score, result_status, verbal_evaluation, comment, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE score = VALUES(score), result_status = VALUES(result_status),
                        verbal_evaluation = VALUES(verbal_evaluation), comment = VALUES(comment), updated_by = VALUES(updated_by)',
                    [$this->schoolId(), (int) $assessment['id'], (int) $student['id'], $score, $status, $after[2], $after[3], $this->auth()->id()]
                );
                $changed++;
            }
        });

        if ($changed > 0) {
            // A change after publication is a correction of record and is marked as such in the log.
            $this->audit($assessment['status'] === 'published' ? 'correct_results' : 'update', 'assessment', $id, null, ['results_changed' => $changed]);
        }
        if ($errors !== []) {
            return $this->redirect("/assessments/$id", null, __('assessments.errors.bad_scores', ['max' => format_hours($max), 'names' => implode(', ', array_slice($errors, 0, 5))]));
        }
        return $this->redirect("/assessments/$id", 'assessments.saved');
    }

    public function publish(Request $request, int $id): Response
    {
        $this->guardWritable();
        $assessment = $this->findEditable($id);
        $publish = $assessment['status'] !== 'published';
        $this->db()->update('assessments', [
            'status' => $publish ? 'published' : 'draft', 'published_at' => $publish ? date('Y-m-d H:i:s') : null,
        ], ['id' => $id]);
        $this->audit($publish ? 'publish' : 'unpublish', 'assessment', $id);
        return $this->redirect("/assessments/$id", $publish ? 'assessments.published' : 'assessments.unpublished');
    }
}
