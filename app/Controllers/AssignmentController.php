<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Teaching assignments: who teaches which subject in which class this year. Managed from the class page. */
final class AssignmentController extends Controller
{
    public function store(Request $request): Response
    {
        $schoolId = $this->schoolId();
        $year = $this->auth()->year() ?? throw new HttpException(404);
        $classId = $request->int('class_id');
        // The class must belong to this school and to the working year.
        $class = $this->db()->one(
            'SELECT id FROM classes WHERE id = ? AND school_id = ? AND academic_year_id = ?',
            [$classId, $schoolId, $year['id']]
        ) ?? throw new HttpException(404);
        $back = "/classes/$classId";
        if ($year['is_locked']) {
            return $this->redirect($back, null, 'app.errors.year_locked');
        }

        $v = Validator::make($request->post, [
            'teacher_id' => 'required|int', 'subject_id' => 'required|int',
            'weekly_hours' => ['regex:/^\d{1,2}([.,]\d)?$/'], 'group_name' => 'max:50',
        ], 'assignments.fields');
        $data = $v->validated();
        if (!$v->fails()) {
            if ($this->db()->value('SELECT 1 FROM teachers WHERE id = ? AND school_id = ? AND deleted_at IS NULL', [$data['teacher_id'], $schoolId]) === null) {
                $v->addError('teacher_id', __('validation.in', ['field' => __('assignments.fields.teacher_id')]));
            }
            if ($this->db()->value('SELECT 1 FROM subjects WHERE id = ? AND school_id = ?', [$data['subject_id'], $schoolId]) === null) {
                $v->addError('subject_id', __('validation.in', ['field' => __('assignments.fields.subject_id')]));
            }
        }
        $group = (string) ($data['group_name'] ?? '');
        if (!$v->fails() && $this->db()->value(
            'SELECT 1 FROM teaching_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ? AND group_name = ?',
            [$data['teacher_id'], $classId, $data['subject_id'], $group]
        ) !== null) {
            $v->addError('teacher_id', __('assignments.errors.duplicate'));
        }
        if ($v->fails()) {
            return $this->invalid($back, $v->errors(), $request->post);
        }

        $row = [
            'school_id' => $schoolId, 'academic_year_id' => (int) $year['id'], 'class_id' => (int) $class['id'],
            'teacher_id' => $data['teacher_id'], 'subject_id' => $data['subject_id'], 'group_name' => $group,
            'weekly_hours' => $data['weekly_hours'] === null ? null : str_replace(',', '.', $data['weekly_hours']),
        ];
        $id = $this->db()->insert('teaching_assignments', $row);
        $this->audit('create', 'teaching_assignment', $id, null, $row);
        return $this->redirect($back, 'app.created');
    }

    public function destroy(Request $request, int $id): Response
    {
        $row = $this->db()->one(
            'SELECT ta.*, y.is_locked FROM teaching_assignments ta JOIN academic_years y ON y.id = ta.academic_year_id
              WHERE ta.id = ? AND ta.school_id = ?',
            [$id, $this->schoolId()]
        ) ?? throw new HttpException(404);
        $back = '/classes/' . (int) $row['class_id'];
        if ($row['is_locked']) {
            return $this->redirect($back, null, 'app.errors.year_locked');
        }
        try {
            $this->db()->run('DELETE FROM teaching_assignments WHERE id = ?', [$id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirect($back, null, 'app.errors.in_use');
            }
            throw $e;
        }
        $this->audit('delete', 'teaching_assignment', $id, [
            'teacher_id' => $row['teacher_id'], 'class_id' => $row['class_id'], 'subject_id' => $row['subject_id'],
        ], null);
        return $this->redirect($back, 'app.deleted');
    }
}
