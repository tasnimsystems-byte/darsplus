<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AccessScope;

/**
 * Professional development of a teacher: goals for the year and training courses.
 * Managed by holders of staff_dev.update whose role covers the whole school;
 * every teacher reads their own page ("my development"), which also lists the
 * observations that were shared with them.
 */
final class StaffDevController extends Controller
{
    public const GOAL_STATUSES = ['open', 'achieved', 'dropped'];
    public const TRAINING_STATUSES = ['planned', 'in_progress', 'completed'];

    private function teacher(int $teacherId, bool $forWrite = false): array
    {
        $teacher = $this->db()->one('SELECT * FROM teachers WHERE id = ? AND school_id = ? AND deleted_at IS NULL', [$teacherId, $this->schoolId()])
            ?? throw new HttpException(404);
        $own = (int) $teacher['user_id'] === $this->auth()->id();
        $manager = (new AccessScope($this->db(), $this->auth()))->isUnrestricted();
        if ($forWrite ? !$manager : (!$manager && !$own)) {
            throw new HttpException($own ? 403 : 404);
        }
        return $teacher;
    }

    private function render(array $teacher, bool $own): Response
    {
        $year = $this->auth()->year();
        $yearId = (int) ($year['id'] ?? 0);
        $id = (int) $teacher['id'];
        return $this->view('staffdev/show', [
            'teacher' => $teacher, 'year' => $year, 'own' => $own,
            'goals' => $this->db()->all('SELECT * FROM teacher_goals WHERE teacher_id = ? AND academic_year_id = ? ORDER BY id', [$id, $yearId]),
            'trainings' => $this->db()->all('SELECT * FROM teacher_trainings WHERE teacher_id = ? ORDER BY start_date IS NULL, start_date DESC, id DESC', [$id]),
            // The teacher sees only what was shared with them; a manager is sent to the observations module instead.
            'observations' => $own ? $this->db()->all(
                'SELECT o.id, o.record_type, o.observed_on, o.strengths, o.areas_for_growth, o.feedback, u.full_name AS observer_name
                   FROM observations o LEFT JOIN users u ON u.id = o.observer_user_id
                  WHERE o.teacher_id = ? AND o.academic_year_id = ? AND o.shared_with_teacher = 1 AND o.deleted_at IS NULL
                  ORDER BY o.observed_on DESC',
                [$id, $yearId]
            ) : [],
            'canEdit' => !$own && can('staff_dev.update') && $year !== null && !$year['is_locked'],
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $teacher = $this->teacher($id);
        return $this->render($teacher, (int) $teacher['user_id'] === $this->auth()->id());
    }

    public function mine(Request $request): Response
    {
        $teacher = $this->db()->one(
            'SELECT * FROM teachers WHERE user_id = ? AND school_id = ? AND deleted_at IS NULL',
            [$this->auth()->id(), $this->schoolId()]
        );
        if ($teacher === null) {
            return $this->view('staffdev/none');
        }
        if ($this->db()->value('SELECT 1 FROM observations WHERE teacher_id = ? AND shared_with_teacher = 1 AND deleted_at IS NULL LIMIT 1', [$teacher['id']]) !== null) {
            $this->audit('view', 'own_observations', (int) $teacher['id']);
        }
        return $this->render($teacher, true);
    }

    private function writable(int $teacherId): array
    {
        $teacher = $this->teacher($teacherId, true);
        $year = $this->auth()->year() ?? throw new RedirectException('/years', 'app.errors.no_year');
        if ($year['is_locked']) {
            throw new RedirectException("/staff-development/$teacherId", 'app.errors.year_locked');
        }
        return [$teacher, $year];
    }

    public function storeGoal(Request $request, int $id): Response
    {
        [, $year] = $this->writable($id);
        $v = Validator::make($request->post, ['title' => 'required|max:190', 'success_measure' => 'max:2000', 'due_date' => 'date'], 'staffdev.goal_fields');
        if ($v->fails()) {
            return $this->invalid("/staff-development/$id", $v->errors(), $request->post);
        }
        $goalId = $this->db()->insert('teacher_goals', $v->validated() + [
            'school_id' => $this->schoolId(), 'academic_year_id' => (int) $year['id'], 'teacher_id' => $id,
        ]);
        $this->audit('create', 'teacher_goal', $goalId, null, ['teacher_id' => $id]);
        return $this->redirect("/staff-development/$id", 'app.created');
    }

    public function updateGoal(Request $request, int $id, int $goal): Response
    {
        $this->writable($id);
        $status = $request->str('status');
        if (!in_array($status, self::GOAL_STATUSES, true)
            || $this->db()->value('SELECT 1 FROM teacher_goals WHERE id = ? AND teacher_id = ?', [$goal, $id]) === null) {
            throw new HttpException(404);
        }
        $this->db()->update('teacher_goals', ['status' => $status], ['id' => $goal]);
        $this->audit('update', 'teacher_goal', $goal, null, ['status' => $status]);
        return $this->redirect("/staff-development/$id", 'app.updated');
    }

    public function destroyGoal(Request $request, int $id, int $goal): Response
    {
        $this->writable($id);
        if ($this->db()->run('DELETE FROM teacher_goals WHERE id = ? AND teacher_id = ?', [$goal, $id])->rowCount() === 0) {
            throw new HttpException(404);
        }
        $this->audit('delete', 'teacher_goal', $goal);
        return $this->redirect("/staff-development/$id", 'app.deleted');
    }

    public function storeTraining(Request $request, int $id): Response
    {
        $this->writable($id);
        $v = Validator::make($request->post, [
            'title' => 'required|max:190', 'provider' => 'max:150', 'hours' => 'int|min:1|max:2000', 'start_date' => 'date',
            'status' => 'required|in:' . implode(',', self::TRAINING_STATUSES),
        ], 'staffdev.training_fields');
        if ($v->fails()) {
            return $this->invalid("/staff-development/$id", $v->errors(), $request->post);
        }
        $trainingId = $this->db()->insert('teacher_trainings', $v->validated() + ['school_id' => $this->schoolId(), 'teacher_id' => $id]);
        $this->audit('create', 'teacher_training', $trainingId, null, ['teacher_id' => $id]);
        return $this->redirect("/staff-development/$id", 'app.created');
    }

    public function destroyTraining(Request $request, int $id, int $training): Response
    {
        $this->writable($id);
        if ($this->db()->run('DELETE FROM teacher_trainings WHERE id = ? AND teacher_id = ?', [$training, $id])->rowCount() === 0) {
            throw new HttpException(404);
        }
        $this->audit('delete', 'teacher_training', $training);
        return $this->redirect("/staff-development/$id", 'app.deleted');
    }
}
