<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Notifier;

/**
 * Meetings with agenda, participants, minutes and decisions. A decision can be
 * turned into a task, and each meeting page lists the decisions still open
 * from earlier meetings of the same kind.
 *
 * A meeting is seen by its organiser, its participants, and users whose role
 * covers the whole school. Changing it is for the organiser or such a user.
 */
final class MeetingController extends CrudController
{
    public const TYPES = ['management', 'pedagogical', 'subject_team', 'homeroom', 'grade_level', 'individual'];
    public const STATUSES = ['planned', 'held', 'cancelled'];

    protected function definition(): array
    {
        $types = $statuses = [];
        foreach (self::TYPES as $type) {
            $types[$type] = __('meetings.types.' . $type);
        }
        foreach (self::STATUSES as $status) {
            $statuses[$status] = __('meetings.statuses.' . $status);
        }
        return [
            'table' => 'meetings', 'entity' => 'meeting', 'path' => '/meetings', 'lang' => 'meetings', 'module' => 'meetings',
            'scope' => 'year', 'order' => 't.meeting_date DESC, t.start_time DESC, t.id DESC',
            'softDelete' => true, 'userstamps' => true, 'show' => true, 'nameField' => 'title', 'search' => ['t.title'],
            'filters' => [
                'meeting_type' => ['label' => 'meetings.fields.meeting_type', 'options' => $types, 'sql' => 't.meeting_type = ?'],
                'status' => ['label' => 'meetings.fields.status', 'options' => $statuses, 'sql' => 't.status = ?'],
            ],
            'fields' => [
                'title' => ['rules' => 'required|max:190', 'list' => true, 'attrs' => 'maxlength="190"'],
                'meeting_type' => ['type' => 'select', 'rules' => 'required', 'options' => $types, 'list' => true],
                'meeting_date' => ['type' => 'date', 'rules' => 'required', 'list' => true],
                'start_time' => ['rules' => ['regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'list' => true, 'attrs' => 'dir="ltr" placeholder="14:30" maxlength="5" inputmode="numeric"'],
                'location' => ['rules' => 'max:120', 'attrs' => 'maxlength="120"'],
                'student_id' => ['type' => 'select', 'help' => 'meetings.student_help', 'options' => fn () => $this->studentOptions()],
                'agenda' => ['type' => 'textarea', 'rules' => 'max:5000', 'help' => 'meetings.agenda_help'],
                'participant_ids' => ['type' => 'multicheck', 'virtual' => true, 'options' => fn () => $this->userOptions()],
                'status' => ['type' => 'select', 'rules' => 'required', 'options' => $statuses, 'list' => true, 'default' => 'planned'],
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
        return ['(t.organizer_user_id = ? OR EXISTS (SELECT 1 FROM meeting_participants mp WHERE mp.meeting_id = t.id AND mp.user_id = ?))', [$me, $me]];
    }

    protected function listQuery(): array
    {
        [$where, $params] = $this->visibility();
        return ['where' => $where, 'params' => $params];
    }

    protected function rowVisible(array $row): bool
    {
        [$where, $params] = $this->visibility();
        return $this->db()->value("SELECT 1 FROM meetings t WHERE t.id = ? AND $where", [$row['id'], ...$params]) !== null;
    }

    protected function rowEditable(array $row): bool
    {
        return (int) $row['organizer_user_id'] === $this->auth()->id() || $this->accessScope()->isUnrestricted();
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        $year = $this->year();
        if ($data['meeting_date'] < $year['start_date'] || $data['meeting_date'] > $year['end_date']) {
            $v->addError('meeting_date', __('meetings.errors.date_outside'));
        }
        if ($data['meeting_type'] === 'individual' && $data['student_id'] === null && $data['participant_ids'] === []) {
            $v->addError('participant_ids', __('meetings.errors.individual'));
        }
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if ($existing === null) {
            $data['organizer_user_id'] = $this->auth()->id();
        }
        return $data;
    }

    protected function afterSave(int $id, ?array $existing): void
    {
        $current = array_map('intval', $this->db()->column('SELECT user_id FROM meeting_participants WHERE meeting_id = ?', [$id]));
        $wanted = $this->virtual['participant_ids'];
        foreach (array_diff($current, $wanted) as $userId) {
            $this->db()->run('DELETE FROM meeting_participants WHERE meeting_id = ? AND user_id = ?', [$id, $userId]);
        }
        $meeting = $this->db()->one('SELECT title, meeting_date FROM meetings WHERE id = ?', [$id]);
        foreach (array_diff($wanted, $current) as $userId) {
            $this->db()->insert('meeting_participants', ['school_id' => $this->schoolId(), 'meeting_id' => $id, 'user_id' => $userId]);
            if ($userId !== $this->auth()->id()) {
                Notifier::send($userId, $this->schoolId(), 'meeting', __('meetings.notify_invited', [
                    'title' => $meeting['title'], 'date' => date('d/m/Y', strtotime($meeting['meeting_date'])),
                ]), null, "/meetings/$id");
            }
        }
    }

    protected function virtualValues(array $row): array
    {
        return ['participant_ids' => array_map('intval', $this->db()->column('SELECT user_id FROM meeting_participants WHERE meeting_id = ?', [$row['id']]))];
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/meetings/$id";
    }

    public function show(Request $request, int $id): Response
    {
        $meeting = $this->find($id);
        [$where, $params] = $this->visibility();
        return $this->view('meetings/show', [
            'meeting' => $meeting,
            'meta' => $this->db()->one(
                "SELECT u.full_name AS organizer_name, CONCAT(s.last_name, ' ', s.first_name) AS student_name
                   FROM meetings m LEFT JOIN users u ON u.id = m.organizer_user_id LEFT JOIN students s ON s.id = m.student_id WHERE m.id = ?",
                [$id]
            ),
            'participants' => $this->db()->all(
                'SELECT mp.user_id, mp.attendance, u.full_name FROM meeting_participants mp JOIN users u ON u.id = mp.user_id
                  WHERE mp.meeting_id = ? ORDER BY u.full_name',
                [$id]
            ),
            'decisions' => $this->db()->all(
                "SELECT d.*, u.full_name AS owner_name,
                        (SELECT tk.id FROM tasks tk WHERE tk.source_type = 'meeting_decision' AND tk.source_id = d.id AND tk.deleted_at IS NULL LIMIT 1) AS task_id
                   FROM meeting_decisions d LEFT JOIN users u ON u.id = d.owner_user_id WHERE d.meeting_id = ? ORDER BY d.id",
                [$id]
            ),
            // Follow-up: decisions still open from earlier meetings of the same type that this user may see.
            'previous' => $this->db()->all(
                "SELECT d.id, d.decision, d.due_date, u.full_name AS owner_name, t.id AS meeting_id, t.title, t.meeting_date
                   FROM meeting_decisions d JOIN meetings t ON t.id = d.meeting_id LEFT JOIN users u ON u.id = d.owner_user_id
                  WHERE t.school_id = ? AND t.meeting_type = ? AND t.deleted_at IS NULL AND d.status = 'open'
                    AND (t.meeting_date < ? OR (t.meeting_date = ? AND t.id < ?)) AND $where
                  ORDER BY t.meeting_date DESC, d.id LIMIT 30",
                [$this->schoolId(), $meeting['meeting_type'], $meeting['meeting_date'], $meeting['meeting_date'], $id, ...$params]
            ),
            'users' => $this->userOptions(),
            'canEdit' => can('meetings.update') && $this->rowEditable($meeting) && !$this->year()['is_locked'],
            'userId' => $this->auth()->id(),
        ]);
    }

    private function editable(int $id): array
    {
        $this->guardWritable();
        return $this->findEditable($id);
    }

    /** Minutes and who actually attended. */
    public function saveMinutes(Request $request, int $id): Response
    {
        $meeting = $this->editable($id);
        $minutes = mb_substr($request->str('minutes'), 0, 20000);
        $attended = $request->ids('attended');
        $this->db()->transaction(function () use ($id, $minutes, $attended, $request): void {
            $this->db()->update('meetings', [
                'minutes' => $minutes === '' ? null : $minutes,
                'status' => $request->has('mark_held') ? 'held' : $this->db()->value('SELECT status FROM meetings WHERE id = ?', [$id]),
            ], ['id' => $id]);
            if ($request->has('mark_held')) {
                foreach ($this->db()->column('SELECT user_id FROM meeting_participants WHERE meeting_id = ?', [$id]) as $userId) {
                    $this->db()->run(
                        'UPDATE meeting_participants SET attendance = ? WHERE meeting_id = ? AND user_id = ?',
                        [in_array((int) $userId, $attended, true) ? 'attended' : 'absent', $id, $userId]
                    );
                }
            }
        });
        $this->audit('update', 'meeting_minutes', $id, null, ['length' => mb_strlen($minutes), 'previous_length' => mb_strlen((string) $meeting['minutes'])]);
        return $this->redirect("/meetings/$id", 'app.updated');
    }

    public function storeDecision(Request $request, int $id): Response
    {
        $this->editable($id);
        $users = $this->userOptions();
        $v = Validator::make($request->post, [
            'decision' => 'required|max:2000', 'owner_user_id' => 'in:' . implode(',', array_keys($users)), 'due_date' => 'date',
        ], 'meetings.decision_fields');
        if ($v->fails()) {
            return $this->invalid("/meetings/$id", $v->errors(), $request->post);
        }
        $data = $v->validated();
        $data['owner_user_id'] = $data['owner_user_id'] === null ? null : (int) $data['owner_user_id'];
        $decisionId = $this->db()->transaction(function () use ($data, $id, $request): int {
            $decisionId = $this->db()->insert('meeting_decisions', $data + ['school_id' => $this->schoolId(), 'meeting_id' => $id]);
            // "Decision → task": the responsible person gets it on their task list.
            if ($request->has('create_task') && $data['owner_user_id'] !== null) {
                $taskId = $this->db()->insert('tasks', [
                    'school_id' => $this->schoolId(), 'title' => mb_substr($data['decision'], 0, 190), 'description' => $data['decision'],
                    'assignee_user_id' => $data['owner_user_id'], 'assigned_by' => $this->auth()->id(), 'due_date' => $data['due_date'],
                    'source_type' => 'meeting_decision', 'source_id' => $decisionId, 'created_by' => $this->auth()->id(),
                ]);
                if ($data['owner_user_id'] !== $this->auth()->id()) {
                    Notifier::send($data['owner_user_id'], $this->schoolId(), 'task', __('tasks.notify_assigned', ['title' => mb_substr($data['decision'], 0, 100)]), null, "/tasks/$taskId");
                }
            }
            return $decisionId;
        });
        $this->audit('create', 'meeting_decision', $decisionId, null, $data);
        return $this->redirect("/meetings/$id", 'app.created');
    }

    /** The owner of a decision, or whoever may edit the meeting, marks it done. Works from later meetings too. */
    public function toggleDecision(Request $request, int $id, int $decision): Response
    {
        $this->guardWritable();
        $meeting = $this->find($id);
        $row = $this->db()->one(
            'SELECT d.*, m.organizer_user_id AS origin_organizer FROM meeting_decisions d JOIN meetings m ON m.id = d.meeting_id
              WHERE d.id = ? AND d.school_id = ? AND (d.meeting_id = ? OR m.meeting_type = ?)',
            [$decision, $this->schoolId(), $id, $meeting['meeting_type']]
        ) ?? throw new HttpException(404);
        if (!$this->rowEditable($meeting) && (int) $row['owner_user_id'] !== $this->auth()->id()) {
            throw new HttpException(403);
        }
        $status = $row['status'] === 'open' ? 'done' : 'open';
        $this->db()->update('meeting_decisions', ['status' => $status], ['id' => $decision]);
        $this->audit('update', 'meeting_decision', $decision, ['status' => $row['status']], ['status' => $status]);
        return $this->redirect("/meetings/$id", 'app.updated');
    }

    public function destroyDecision(Request $request, int $id, int $decision): Response
    {
        $this->editable($id);
        if ($this->db()->run('DELETE FROM meeting_decisions WHERE id = ? AND meeting_id = ?', [$decision, $id])->rowCount() === 0) {
            throw new HttpException(404);
        }
        $this->audit('delete', 'meeting_decision', $decision);
        return $this->redirect("/meetings/$id", 'app.deleted');
    }
}
