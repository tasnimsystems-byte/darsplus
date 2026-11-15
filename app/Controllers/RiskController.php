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
use App\Services\RiskIndicatorService;

/**
 * Indications for review. They are shown only for students whose class the user
 * sees in full (homeroom teacher, coordinators, counsellor, management): the
 * evidence spans several subjects, so a teacher of a single subject does not see it.
 * The system proposes; the decision recorded here is always a person's.
 */
final class RiskController extends Controller
{
    public const DECISIONS = ['monitoring', 'intervention', 'dismissed'];

    private function year(): array
    {
        return $this->auth()->year() ?? throw new RedirectException('/years', 'app.errors.no_year');
    }

    private function terms(array $year): array
    {
        return $this->db()->all('SELECT * FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order', [$year['id']]);
    }

    /** @return array{0: string, 1: array} FROM/WHERE fragment and its parameters for visible indications */
    private function visible(array $year): array
    {
        [$condition, $params] = (new AccessScope($this->db(), $this->auth()))->fullClassCondition('e.class_id');
        return [
            "FROM risk_indications ri
             JOIN students s ON s.id = ri.student_id AND s.deleted_at IS NULL
             JOIN risk_criteria rc ON rc.id = ri.criterion_id
             JOIN academic_terms tm ON tm.id = ri.term_id
             JOIN class_enrollments e ON e.student_id = s.id AND e.left_on IS NULL
             JOIN classes c ON c.id = e.class_id AND c.academic_year_id = tm.academic_year_id
            WHERE ri.school_id = ? AND tm.academic_year_id = ? AND $condition",
            [$this->schoolId(), $year['id'], ...$params],
        ];
    }

    public function index(Request $request): Response
    {
        $year = $this->year();
        $terms = array_column($this->terms($year), 'name', 'id');
        [$from, $params] = $this->visible($year);

        $filters = ['term_id' => $request->int('term_id'), 'status' => $request->str('status'), 'class_id' => $request->int('class_id')];
        if (isset($terms[$filters['term_id']])) {
            $from .= ' AND ri.term_id = ?';
            $params[] = $filters['term_id'];
        }
        $statuses = ['new', ...self::DECISIONS];
        if (in_array($filters['status'], $statuses, true)) {
            $from .= ' AND ri.review_status = ?';
            $params[] = $filters['status'];
        } else {
            $filters['status'] = '';
        }
        if ($filters['class_id'] > 0) {
            $from .= ' AND c.id = ?';
            $params[] = $filters['class_id'];
        }
        $rows = $this->db()->all(
            "SELECT ri.id, ri.review_status, ri.created_at, s.id AS student_id, s.first_name, s.last_name,
                    c.id AS class_id, c.name AS class_name, rc.name AS criterion_name, tm.name AS term_name
             $from ORDER BY ri.review_status = 'new' DESC, c.name, s.last_name, s.first_name, ri.id LIMIT 500",
            $params
        );
        return $this->view('risk/index', [
            'rows' => $rows, 'terms' => $terms, 'filters' => $filters, 'statuses' => $statuses,
            'classes' => array_column($rows, 'class_name', 'class_id'),
            'canRun' => can('risk.create') && !$year['is_locked'] && $terms !== [],
        ]);
    }

    public function run(Request $request): Response
    {
        $year = $this->year();
        if ($year['is_locked']) {
            return $this->redirect('/risk', null, 'app.errors.year_locked');
        }
        $term = null;
        foreach ($this->terms($year) as $candidate) {
            if ((int) $candidate['id'] === $request->int('term_id')) {
                $term = $candidate;
            }
        }
        if ($term === null) {
            return $this->redirect('/risk', null, 'risk.errors.term');
        }
        $result = (new RiskIndicatorService($this->db()))->run($this->schoolId(), $year, $term);
        $this->audit('run', 'risk_indication', null, null, $result + ['term_id' => (int) $term['id']]);
        return $this->redirect('/risk?term_id=' . (int) $term['id'], __('risk.run_done', $result));
    }

    public function show(Request $request, int $id): Response
    {
        [$from, $params] = $this->visible($this->year());
        $row = $this->db()->one(
            "SELECT ri.*, s.first_name, s.last_name, c.name AS class_name, rc.name AS criterion_name, rc.criterion_type, tm.name AS term_name,
                    (SELECT u.full_name FROM users u WHERE u.id = ri.reviewed_by) AS reviewer_name
             $from AND ri.id = ?",
            [...$params, $id]
        ) ?? throw new HttpException(404);
        $this->audit('view', 'risk_indication', $id);
        return $this->view('risk/show', [
            'row' => $row,
            'evidence' => json_decode((string) $row['evidence'], true) ?: [],
            'canReview' => can('risk.update') && !$this->year()['is_locked'],
        ]);
    }

    public function review(Request $request, int $id): Response
    {
        $year = $this->year();
        [$from, $params] = $this->visible($year);
        $row = $this->db()->one("SELECT ri.id, ri.review_status $from AND ri.id = ?", [...$params, $id]) ?? throw new HttpException(404);
        if ($year['is_locked']) {
            return $this->redirect("/risk/$id", null, 'app.errors.year_locked');
        }
        $v = Validator::make($request->post, [
            'decision' => 'required|in:' . implode(',', self::DECISIONS), 'review_note' => 'max:2000',
        ], 'risk.fields');
        $data = $v->validated();
        // Setting an indication aside needs a reason, so the decision can be understood later.
        if (!$v->fails() && $data['decision'] === 'dismissed' && $data['review_note'] === null) {
            $v->addError('review_note', __('risk.errors.note_required'));
        }
        if ($v->fails()) {
            return $this->invalid("/risk/$id", $v->errors(), $request->post);
        }
        $this->db()->update('risk_indications', [
            'review_status' => $data['decision'], 'review_note' => $data['review_note'],
            'reviewed_by' => $this->auth()->id(), 'reviewed_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
        $this->audit('review', 'risk_indication', $id, ['review_status' => $row['review_status']], ['review_status' => $data['decision']]);
        return $this->redirect('/risk', 'risk.reviewed');
    }
}
