<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Lesson visits, observations and professional conversations.
 *
 * Seen by: the observer who wrote the record, and holders of observations.view
 * whose role covers the whole school (management). The observed teacher reads a
 * record only after it was shared, on their own development page. Every opening
 * of a record is written to the audit log.
 */
final class ObservationController extends CrudController
{
    public const TYPES = ['lesson_visit', 'observation', 'conversation'];

    protected function definition(): array
    {
        $types = [];
        foreach (self::TYPES as $type) {
            $types[$type] = __('observations.types.' . $type);
        }
        return [
            'table' => 'observations', 'entity' => 'observation', 'path' => '/observations', 'lang' => 'observations',
            'module' => 'observations', 'scope' => 'year', 'order' => 't.observed_on DESC, t.id DESC',
            'softDelete' => true, 'userstamps' => true, 'show' => true,
            'rowName' => static fn ($row) => date('d/m/Y', strtotime($row['observed_on'])),
            'listExtra' => ['teacher_name' => 'observations.fields.teacher_id', 'observer_name' => 'observations.observer'],
            'filters' => ['record_type' => ['label' => 'observations.fields.record_type', 'options' => $types, 'sql' => 't.record_type = ?']],
            'fields' => [
                'observed_on' => ['type' => 'date', 'rules' => 'required', 'list' => true, 'default' => date('Y-m-d')],
                'teacher_id' => ['type' => 'select', 'rules' => 'required', 'options' => fn () => $this->options(
                    "SELECT id, CONCAT(last_name, ' ', first_name) AS label FROM teachers
                      WHERE school_id = ? AND deleted_at IS NULL ORDER BY last_name, first_name",
                    [$this->schoolId()]
                )],
                'record_type' => ['type' => 'select', 'rules' => 'required', 'options' => $types, 'list' => true],
                'strengths' => ['type' => 'textarea', 'rules' => 'max:4000'],
                'areas_for_growth' => ['type' => 'textarea', 'rules' => 'max:4000'],
                'feedback' => ['type' => 'textarea', 'rules' => 'max:4000', 'help' => 'observations.feedback_help'],
                'shared_with_teacher' => ['type' => 'checkbox', 'list' => true, 'help' => 'observations.shared_help'],
            ],
        ];
    }

    /** @return array{0: string, 1: array} */
    private function visibility(): array
    {
        return $this->accessScope()->isUnrestricted()
            ? ['1 = 1', []]
            : ['t.observer_user_id = ?', [(int) $this->auth()->id()]];
    }

    protected function listQuery(): array
    {
        [$where, $params] = $this->visibility();
        return [
            'select' => ", CONCAT(tc.last_name, ' ', tc.first_name) AS teacher_name, ou.full_name AS observer_name",
            'join' => 'JOIN teachers tc ON tc.id = t.teacher_id LEFT JOIN users ou ON ou.id = t.observer_user_id',
            'where' => $where, 'params' => $params,
        ];
    }

    protected function rowVisible(array $row): bool
    {
        return $this->accessScope()->isUnrestricted() || (int) $row['observer_user_id'] === $this->auth()->id();
    }

    /** Only the author changes a record; management reads it. */
    protected function rowEditable(array $row): bool
    {
        return (int) $row['observer_user_id'] === $this->auth()->id();
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        if ($data['observed_on'] > date('Y-m-d')) {
            $v->addError('observed_on', __('observations.errors.future'));
        }
        // Nobody writes an observation about themselves.
        if ($this->db()->value('SELECT 1 FROM teachers WHERE id = ? AND user_id = ?', [$data['teacher_id'], $this->auth()->id()]) !== null) {
            $v->addError('teacher_id', __('observations.errors.self'));
        }
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if ($existing === null) {
            $data['observer_user_id'] = $this->auth()->id();
        }
        return $data;
    }

    protected function afterSave(int $id, ?array $existing): void
    {
        $row = $this->db()->one(
            'SELECT o.shared_with_teacher, t.user_id FROM observations o JOIN teachers t ON t.id = o.teacher_id WHERE o.id = ?',
            [$id]
        );
        // Tell the teacher when a record becomes visible to them — the notice carries no content.
        if ($row['shared_with_teacher'] && !($existing['shared_with_teacher'] ?? 0) && $row['user_id'] !== null) {
            \App\Services\Notifier::send((int) $row['user_id'], $this->schoolId(), 'observation', __('observations.notify_shared'), null, '/my-development');
        }
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/observations/$id";
    }

    public function show(Request $request, int $id): Response
    {
        $row = $this->find($id);
        $this->audit('view', 'observation', $id);
        return $this->view('observations/show', [
            'row' => $row,
            'meta' => $this->db()->one(
                "SELECT CONCAT(t.last_name, ' ', t.first_name) AS teacher_name, u.full_name AS observer_name
                   FROM observations o JOIN teachers t ON t.id = o.teacher_id LEFT JOIN users u ON u.id = o.observer_user_id WHERE o.id = ?",
                [$id]
            ),
            'recommendations' => $this->db()->all('SELECT * FROM observation_recommendations WHERE observation_id = ? ORDER BY id', [$id]),
            'canEdit' => can('observations.update') && $this->rowEditable($row) && !$this->year()['is_locked'],
        ]);
    }

    public function storeRecommendation(Request $request, int $id): Response
    {
        $this->guardWritable();
        $this->findEditable($id);
        $v = Validator::make($request->post, ['recommendation' => 'required|max:500', 'follow_up_date' => 'date'], 'observations.recommendation_fields');
        if ($v->fails()) {
            return $this->invalid("/observations/$id", $v->errors(), $request->post);
        }
        $recommendationId = $this->db()->insert('observation_recommendations', $v->validated() + ['school_id' => $this->schoolId(), 'observation_id' => $id]);
        $this->audit('create', 'observation_recommendation', $recommendationId);
        return $this->redirect("/observations/$id", 'app.created');
    }

    public function updateRecommendation(Request $request, int $id, int $recommendation): Response
    {
        $this->guardWritable();
        $this->findEditable($id);
        $row = $this->db()->one('SELECT * FROM observation_recommendations WHERE id = ? AND observation_id = ?', [$recommendation, $id])
            ?? throw new HttpException(404);
        $status = $row['status'] === 'done' ? 'open' : 'done';
        $this->db()->update('observation_recommendations', [
            'status' => $status, 'follow_up_note' => mb_substr($request->str('follow_up_note'), 0, 500) ?: $row['follow_up_note'],
        ], ['id' => $recommendation]);
        $this->audit('update', 'observation_recommendation', $recommendation, ['status' => $row['status']], ['status' => $status]);
        return $this->redirect("/observations/$id", 'app.updated');
    }
}
