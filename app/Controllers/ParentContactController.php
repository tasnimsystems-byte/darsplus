<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Record of communication with parents. Two texts are kept apart on purpose:
 * internal_notes (staff only, never printed for the family) and shared_summary
 * (what was agreed to pass on). Each record concerns exactly one student.
 *
 * Visible to those who see the student's class in full and to the staff member
 * who made the contact.
 */
final class ParentContactController extends CrudController
{
    public const TYPES = ['meeting', 'call', 'message', 'inquiry'];
    public const FOLLOW_UP = ['none', 'open', 'done'];

    protected function definition(): array
    {
        $types = $followUp = [];
        foreach (self::TYPES as $type) {
            $types[$type] = __('parent_contacts.types.' . $type);
        }
        foreach (self::FOLLOW_UP as $status) {
            $followUp[$status] = __('parent_contacts.follow_up.' . $status);
        }
        return [
            'table' => 'parent_contacts', 'entity' => 'parent_contact', 'path' => '/parent-contacts', 'lang' => 'parent_contacts',
            'module' => 'parent_contacts', 'scope' => 'year', 'order' => 't.contacted_on DESC, t.id DESC',
            'softDelete' => true, 'userstamps' => true, 'show' => true,
            'rowName' => static fn ($row) => date('d/m/Y', strtotime($row['contacted_on'])),
            'listExtra' => ['student_name' => 'parent_contacts.fields.student_id', 'staff_name' => 'parent_contacts.staff'],
            'filters' => [
                'contact_type' => ['label' => 'parent_contacts.fields.contact_type', 'options' => $types, 'sql' => 't.contact_type = ?'],
                'follow_up_status' => ['label' => 'parent_contacts.fields.follow_up_status', 'options' => $followUp, 'sql' => 't.follow_up_status = ?'],
            ],
            'fields' => [
                'contacted_on' => ['type' => 'date', 'rules' => 'required', 'list' => true, 'default' => date('Y-m-d')],
                'student_id' => ['type' => 'select', 'rules' => 'required', 'options' => fn () => $this->studentOptions()],
                'guardian_id' => ['type' => 'select', 'help' => 'parent_contacts.guardian_help', 'options' => fn () => $this->guardianOptions()],
                'contact_type' => ['type' => 'select', 'rules' => 'required', 'options' => $types, 'list' => true],
                'internal_notes' => ['type' => 'textarea', 'rules' => 'max:5000', 'help' => 'parent_contacts.internal_help'],
                'shared_summary' => ['type' => 'textarea', 'rules' => 'max:5000', 'help' => 'parent_contacts.shared_help'],
                'decisions' => ['type' => 'textarea', 'rules' => 'max:3000'],
                'follow_up_date' => ['type' => 'date'],
                'follow_up_status' => ['type' => 'select', 'rules' => 'required', 'options' => $followUp, 'list' => true, 'default' => 'none'],
            ],
        ];
    }

    /** Guardians of the students this user may record contacts for. */
    private function guardianOptions(): array
    {
        $students = array_keys($this->studentOptions());
        if ($students === []) {
            return [];
        }
        return $this->options(
            "SELECT g.id, CONCAT(g.full_name, ' – ', s.last_name, ' ', s.first_name) AS label
               FROM guardians g JOIN students s ON s.id = g.student_id
              WHERE g.school_id = ? AND g.student_id IN (" . implode(',', array_fill(0, count($students), '?')) . ')
              ORDER BY s.last_name, s.first_name, g.full_name',
            [$this->schoolId(), ...$students]
        );
    }

    /** @return array{0: string, 1: array} */
    private function visibility(): array
    {
        [$condition, $params] = $this->accessScope()->fullClassCondition('e.class_id');
        return [
            "(t.staff_user_id = ? OR EXISTS (SELECT 1 FROM class_enrollments e JOIN classes c ON c.id = e.class_id
                WHERE e.student_id = t.student_id AND e.left_on IS NULL AND c.academic_year_id = t.academic_year_id AND $condition))",
            [(int) $this->auth()->id(), ...$params],
        ];
    }

    protected function listQuery(): array
    {
        [$where, $params] = $this->visibility();
        return [
            'select' => ", CONCAT(s.last_name, ' ', s.first_name) AS student_name, su.full_name AS staff_name",
            'join' => 'JOIN students s ON s.id = t.student_id LEFT JOIN users su ON su.id = t.staff_user_id',
            'where' => $where, 'params' => $params,
        ];
    }

    protected function rowVisible(array $row): bool
    {
        [$where, $params] = $this->visibility();
        return $this->db()->value("SELECT 1 FROM parent_contacts t WHERE t.id = ? AND $where", [$row['id'], ...$params]) !== null;
    }

    protected function rowEditable(array $row): bool
    {
        return (int) $row['staff_user_id'] === $this->auth()->id() || $this->accessScope()->isUnrestricted();
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        if ($data['contacted_on'] > date('Y-m-d')) {
            $v->addError('contacted_on', __('parent_contacts.errors.future'));
        }
        // The guardian must be a guardian of this very student.
        if ($data['guardian_id'] !== null && $this->db()->value(
            'SELECT 1 FROM guardians WHERE id = ? AND student_id = ?',
            [$data['guardian_id'], $data['student_id']]
        ) === null) {
            $v->addError('guardian_id', __('parent_contacts.errors.guardian_mismatch'));
        }
        if ($data['follow_up_status'] === 'open' && $data['follow_up_date'] === null) {
            $v->addError('follow_up_date', __('validation.required', ['field' => __('parent_contacts.fields.follow_up_date')]));
        }
    }

    protected function beforeSave(array $data, ?array $existing): array
    {
        if ($existing === null) {
            $data['staff_user_id'] = $this->auth()->id();
        }
        return $data;
    }

    protected function afterStoreRedirect(int $id): string
    {
        return "/parent-contacts/$id";
    }

    private function detail(int $id): array
    {
        return $this->db()->one(
            "SELECT CONCAT(s.last_name, ' ', s.first_name) AS student_name, g.full_name AS guardian_name, g.relation, u.full_name AS staff_name
               FROM parent_contacts t JOIN students s ON s.id = t.student_id
               LEFT JOIN guardians g ON g.id = t.guardian_id LEFT JOIN users u ON u.id = t.staff_user_id WHERE t.id = ?",
            [$id]
        );
    }

    public function show(Request $request, int $id): Response
    {
        $row = $this->find($id);
        $this->audit('view', 'parent_contact', $id);
        return $this->view('parent_contacts/show', [
            'row' => $row, 'meta' => $this->detail($id),
            'canEdit' => can('parent_contacts.update') && $this->rowEditable($row) && !$this->year()['is_locked'],
        ]);
    }

    /** The page meant for the family: the shared summary and decisions only — internal notes are not even loaded. */
    public function summary(Request $request, int $id): Response
    {
        $row = $this->find($id);
        $this->audit('export', 'parent_contact', $id);
        return $this->view('parent_contacts/summary', [
            'row' => array_diff_key($row, ['internal_notes' => 1]),
            'meta' => $this->detail($id),
            'school' => $this->auth()->school(),
        ], 'layouts/print');
    }

    // ---- Guardians (managed from the student card) ------------------------------------

    private function student(int $studentId): int
    {
        // Contact details of a family are for staff who see the whole class of the student.
        if ($this->db()->value('SELECT 1 FROM students WHERE id = ? AND school_id = ? AND deleted_at IS NULL', [$studentId, $this->schoolId()]) === null
            || !$this->accessScope()->canSeeStudentFully($studentId)) {
            throw new HttpException(404);
        }
        return $studentId;
    }

    public function storeGuardian(Request $request, int $id): Response
    {
        $this->student($id);
        $v = Validator::make($request->post, [
            'full_name' => 'required|max:150', 'relation' => 'max:40',
            'phone' => ['max:30', 'regex:/^[0-9+\-() ]+$/'], 'email' => 'email|max:190',
        ], 'parent_contacts.guardian_fields');
        if ($v->fails()) {
            return $this->invalid("/students/$id", $v->errors(), $request->post);
        }
        $guardianId = $this->db()->insert('guardians', $v->validated() + [
            'school_id' => $this->schoolId(), 'student_id' => $id, 'is_primary' => $request->has('is_primary') ? 1 : 0,
        ]);
        $this->audit('create', 'guardian', $guardianId, null, ['student_id' => $id]);
        return $this->redirect("/students/$id", 'app.created');
    }

    public function destroyGuardian(Request $request, int $id, int $guardian): Response
    {
        $this->student($id);
        try {
            $deleted = $this->db()->run('DELETE FROM guardians WHERE id = ? AND student_id = ?', [$guardian, $id])->rowCount();
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirect("/students/$id", null, 'app.errors.in_use');
            }
            throw $e;
        }
        if ($deleted === 0) {
            throw new HttpException(404);
        }
        $this->audit('delete', 'guardian', $guardian, ['student_id' => $id], null);
        return $this->redirect("/students/$id", 'app.deleted');
    }
}
