<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CrudController;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\YearTransitionService;

final class YearController extends CrudController
{
    protected function definition(): array
    {
        return [
            'table' => 'academic_years', 'entity' => 'academic_year', 'path' => '/years',
            'lang' => 'years', 'module' => 'structure', 'order' => 't.start_date DESC, t.id DESC',
            'unique' => [['name']], 'show' => true,
            'fields' => [
                'name' => ['rules' => 'required|max:30', 'list' => true, 'attrs' => 'maxlength="30"', 'help' => 'years.name_help'],
                'start_date' => ['type' => 'date', 'rules' => 'required', 'list' => true],
                'end_date' => ['type' => 'date', 'rules' => 'required', 'list' => true],
                'is_current' => ['type' => 'checkbox', 'list' => true, 'virtual' => true],
                'is_locked' => ['type' => 'checkbox', 'list' => true, 'virtual' => true],
            ],
            'rowActions' => [
                ['path' => '/years/{id}/current', 'label' => 'years.make_current', 'icon' => 'check2-circle',
                    'permission' => 'structure.update', 'when' => static fn ($row) => !$row['is_current']],
                ['path' => '/years/{id}/lock', 'label' => 'years.lock', 'icon' => 'lock', 'permission' => 'structure.update',
                    'confirm' => 'years.confirm_lock', 'when' => static fn ($row) => !$row['is_locked'] && !$row['is_current']],
                ['path' => '/years/{id}/lock', 'label' => 'years.unlock', 'icon' => 'unlock', 'permission' => 'structure.update',
                    'when' => static fn ($row) => (bool) $row['is_locked']],
            ],
        ];
    }

    /** "Current" and "locked" are changed only through their own audited actions, never from the form. */
    public function create(Request $request): Response
    {
        return $this->formWithoutFlags(null);
    }

    public function edit(Request $request, int $id): Response
    {
        return $this->formWithoutFlags($this->find($id));
    }

    private function formWithoutFlags(?array $row): Response
    {
        $fields = array_diff_key($this->fields(), ['is_current' => 1, 'is_locked' => 1]);
        return $this->view('crud/form', ['def' => $this->def(), 'fields' => $fields, 'row' => $row, 'values' => $row ?? []]);
    }

    protected function validateExtra(Validator $v, array $data, ?array $existing): void
    {
        if ($data['end_date'] <= $data['start_date']) {
            $v->addError('end_date', __('app.errors.date_order'));
        }
    }

    protected function afterSave(int $id, ?array $existing): void
    {
        // The first year of a school becomes its current year.
        if ($existing === null && $this->db()->value(
            'SELECT 1 FROM academic_years WHERE school_id = ? AND is_current = 1 LIMIT 1',
            [$this->schoolId()]
        ) === null) {
            $this->db()->update('academic_years', ['is_current' => 1], ['id' => $id]);
        }
    }

    public function show(Request $request, int $id): Response
    {
        $year = $this->find($id);
        return $this->view('years/show', [
            'year' => $year,
            'terms' => $this->db()->all('SELECT * FROM academic_terms WHERE academic_year_id = ? ORDER BY sort_order, start_date', [$id]),
            'classCount' => (int) $this->db()->value('SELECT COUNT(*) FROM classes WHERE academic_year_id = ?', [$id]),
        ]);
    }

    public function makeCurrent(Request $request, int $id): Response
    {
        $year = $this->find($id);
        $this->db()->transaction(function () use ($id): void {
            $this->db()->run('UPDATE academic_years SET is_current = 0 WHERE school_id = ?', [$this->schoolId()]);
            $this->db()->update('academic_years', ['is_current' => 1, 'is_locked' => 0], ['id' => $id]);
        });
        $this->auth()->selectYear($id);
        $this->audit('make_current', 'academic_year', $id, null, ['name' => $year['name']]);
        return $this->redirect('/years', 'years.now_current');
    }

    public function toggleLock(Request $request, int $id): Response
    {
        $year = $this->find($id);
        if ($year['is_current'] && !$year['is_locked']) {
            return $this->redirect('/years', null, 'years.errors.lock_current');
        }
        $locked = $year['is_locked'] ? 0 : 1;
        $this->db()->update('academic_years', ['is_locked' => $locked], ['id' => $id]);
        $this->audit($locked ? 'lock' : 'unlock', 'academic_year', $id, null, ['name' => $year['name']]);
        return $this->redirect('/years', $locked ? 'years.locked' : 'years.unlocked');
    }

    public function storeTerm(Request $request, int $id): Response
    {
        $year = $this->find($id);
        if ($year['is_locked']) {
            return $this->redirect("/years/$id", null, 'app.errors.year_locked');
        }
        $v = Validator::make($request->post, [
            'name' => 'required|max:60', 'start_date' => 'required|date', 'end_date' => 'required|date',
        ], 'years.term_fields');
        $data = $v->validated();
        if (!$v->fails()) {
            if ($data['end_date'] <= $data['start_date']) {
                $v->addError('end_date', __('app.errors.date_order'));
            } elseif ($data['start_date'] < $year['start_date'] || $data['end_date'] > $year['end_date']) {
                $v->addError('start_date', __('years.errors.term_outside'));
            } elseif ($this->db()->value('SELECT 1 FROM academic_terms WHERE academic_year_id = ? AND name = ?', [$id, $data['name']]) !== null) {
                $v->addError('name', __('app.errors.unique', ['field' => __('years.term_fields.name')]));
            }
        }
        if ($v->fails()) {
            return $this->invalid("/years/$id", $v->errors(), $request->post);
        }
        $termId = $this->db()->insert('academic_terms', $data + [
            'school_id' => $this->schoolId(), 'academic_year_id' => $id,
            'sort_order' => 1 + (int) $this->db()->value('SELECT COALESCE(MAX(sort_order), 0) FROM academic_terms WHERE academic_year_id = ?', [$id]),
        ]);
        $this->audit('create', 'academic_term', $termId, null, $data);
        return $this->redirect("/years/$id", 'app.created');
    }

    public function destroyTerm(Request $request, int $id, int $term): Response
    {
        $year = $this->find($id);
        $row = $this->db()->one('SELECT * FROM academic_terms WHERE id = ? AND academic_year_id = ?', [$term, $id])
            ?? throw new HttpException(404);
        if ($year['is_locked']) {
            return $this->redirect("/years/$id", null, 'app.errors.year_locked');
        }
        try {
            $this->db()->run('DELETE FROM academic_terms WHERE id = ?', [$term]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->redirect("/years/$id", null, 'app.errors.in_use');
            }
            throw $e;
        }
        $this->audit('delete', 'academic_term', $term, ['name' => $row['name']], null);
        return $this->redirect("/years/$id", 'app.deleted');
    }

    // ---- Transition to a new year ---------------------------------------------

    public function showTransition(Request $request, int $id): Response
    {
        $year = $this->find($id);
        $service = new YearTransitionService($this->db());
        return $this->view('years/transition', [
            'year' => $year,
            'plan' => $service->proposal($this->schoolId(), $year),
            'levels' => $this->options('SELECT id, name AS label FROM grade_levels WHERE school_id = ? ORDER BY level_number', [$this->schoolId()]),
            'suggested' => $service->suggestedDates($year),
        ]);
    }

    public function transition(Request $request, int $id): Response
    {
        $year = $this->find($id);
        $service = new YearTransitionService($this->db());
        $v = Validator::make($request->post, [
            'name' => 'required|max:30', 'start_date' => 'required|date', 'end_date' => 'required|date',
        ], 'years.fields');
        $data = $v->validated();
        if (!$v->fails()) {
            if ($data['end_date'] <= $data['start_date']) {
                $v->addError('end_date', __('app.errors.date_order'));
            } elseif ($data['start_date'] <= $year['end_date']) {
                $v->addError('start_date', __('years.errors.must_follow'));
            } elseif ($this->db()->value('SELECT 1 FROM academic_years WHERE school_id = ? AND name = ?', [$this->schoolId(), $data['name']]) !== null) {
                $v->addError('name', __('app.errors.unique', ['field' => __('years.fields.name')]));
            }
        }
        $mapping = $service->readMapping($this->schoolId(), $year, $request->post['classes'] ?? []);
        if ($mapping === null) {
            $v->addError('classes', __('years.errors.bad_mapping'));
        }
        if ($v->fails()) {
            return $this->invalid("/years/$id/transition", $v->errors(), $request->post);
        }

        $result = $service->execute($this->schoolId(), $year, $data, $mapping, $request->has('make_current'));
        $this->audit('year_transition', 'academic_year', $result['year_id'], ['from' => $year['name']], $result);
        if ($request->has('make_current')) {
            $this->auth()->selectYear($result['year_id']);
        }
        return $this->redirect('/years/' . $result['year_id'], __('years.transition_done', [
            'classes' => $result['classes'], 'students' => $result['promoted'], 'graduated' => $result['graduated'],
        ]));
    }
}
