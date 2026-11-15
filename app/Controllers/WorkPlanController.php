<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\GoalService;

/** The school's annual work plan: vision → objectives → goals. One plan per academic year. */
final class WorkPlanController extends Controller
{
    private function year(bool $forWrite = false): array
    {
        $year = $this->auth()->year() ?? throw new RedirectException('/years', 'app.errors.no_year');
        if ($forWrite && $year['is_locked']) {
            throw new RedirectException('/work-plan', 'app.errors.year_locked');
        }
        return $year;
    }

    private function plan(array $year, bool $create = false): ?array
    {
        $plan = $this->db()->one('SELECT * FROM work_plans WHERE academic_year_id = ? AND school_id = ?', [$year['id'], $this->schoolId()]);
        if ($plan === null && $create) {
            $id = $this->db()->insert('work_plans', ['school_id' => $this->schoolId(), 'academic_year_id' => (int) $year['id']]);
            $this->audit('create', 'work_plan', $id);
            $plan = $this->db()->one('SELECT * FROM work_plans WHERE id = ?', [$id]);
        }
        return $plan;
    }

    public function index(Request $request): Response
    {
        $year = $this->year();
        $plan = $this->plan($year);
        $objectives = $plan === null ? [] : $this->db()->all(
            'SELECT * FROM plan_objectives WHERE work_plan_id = ? ORDER BY sort_order, id',
            [$plan['id']]
        );
        $goals = [];
        foreach ($this->db()->all(
            'SELECT g.id, g.plan_objective_id, g.title, g.end_date, g.status, g.progress_percent, u.full_name AS owner_name, d.name AS domain_name
               FROM annual_goals g
               LEFT JOIN users u ON u.id = g.owner_user_id
               LEFT JOIN pedagogical_domains d ON d.id = g.domain_id
              WHERE g.school_id = ? AND g.academic_year_id = ? AND g.deleted_at IS NULL
              ORDER BY g.end_date, g.id',
            [$this->schoolId(), $year['id']]
        ) as $goal) {
            $goal['display_status'] = GoalService::displayStatus($goal);
            $goals[(int) $goal['plan_objective_id']][] = $goal;
        }
        return $this->view('workplan/index', [
            'year' => $year, 'plan' => $plan, 'objectives' => $objectives, 'goals' => $goals,
            'summary' => GoalService::summary(array_merge(...array_values($goals) ?: [[]])),
            'canEdit' => can('work_plan.update') && !$year['is_locked'],
        ]);
    }

    public function saveVision(Request $request): Response
    {
        $plan = $this->plan($this->year(true), true);
        $vision = mb_substr($request->str('vision'), 0, 5000);
        $this->db()->update('work_plans', ['vision' => $vision === '' ? null : $vision], ['id' => $plan['id']]);
        $this->audit('update', 'work_plan', (int) $plan['id'], ['vision' => $plan['vision']], ['vision' => $vision]);
        return $this->redirect('/work-plan', 'app.updated');
    }

    public function approve(Request $request): Response
    {
        $plan = $this->plan($this->year(true)) ?? throw new HttpException(404);
        $approve = $plan['status'] !== 'approved';
        $this->db()->update('work_plans', [
            'status' => $approve ? 'approved' : 'draft',
            'approved_by' => $approve ? $this->auth()->id() : null,
            'approved_at' => $approve ? date('Y-m-d H:i:s') : null,
        ], ['id' => $plan['id']]);
        $this->audit($approve ? 'approve' : 'unapprove', 'work_plan', (int) $plan['id']);
        return $this->redirect('/work-plan', $approve ? 'workplan.approved' : 'workplan.unapproved');
    }

    public function storeObjective(Request $request): Response
    {
        $plan = $this->plan($this->year(true), true);
        $v = Validator::make($request->post, ['title' => 'required|max:190', 'description' => 'max:2000'], 'workplan.objective_fields');
        if ($v->fails()) {
            return $this->invalid('/work-plan', $v->errors(), $request->post);
        }
        $id = $this->db()->insert('plan_objectives', $v->validated() + [
            'school_id' => $this->schoolId(), 'work_plan_id' => (int) $plan['id'],
            'sort_order' => 1 + (int) $this->db()->value('SELECT COALESCE(MAX(sort_order), 0) FROM plan_objectives WHERE work_plan_id = ?', [$plan['id']]),
        ]);
        $this->audit('create', 'plan_objective', $id, null, $v->validated());
        return $this->redirect('/work-plan', 'app.created');
    }

    public function destroyObjective(Request $request, int $id): Response
    {
        $this->year(true);
        $row = $this->db()->one('SELECT * FROM plan_objectives WHERE id = ? AND school_id = ?', [$id, $this->schoolId()])
            ?? throw new HttpException(404);
        if ($this->db()->value('SELECT 1 FROM annual_goals WHERE plan_objective_id = ? AND deleted_at IS NULL LIMIT 1', [$id]) !== null) {
            return $this->redirect('/work-plan', null, 'app.errors.in_use');
        }
        try {
            $this->db()->run('DELETE FROM plan_objectives WHERE id = ?', [$id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirect('/work-plan', null, 'app.errors.in_use');
            }
            throw $e;
        }
        $this->audit('delete', 'plan_objective', $id, ['title' => $row['title']], null);
        return $this->redirect('/work-plan', 'app.deleted');
    }
}
