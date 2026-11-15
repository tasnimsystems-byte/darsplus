<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\RedirectException;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Reports\Exporter;
use App\Reports\Reports;
use App\Services\AccessScope;

/**
 * Reports centre. Viewing needs reports.view plus the permission of the data a
 * report draws on; exporting additionally needs reports.export and is recorded
 * in the audit log. All four outputs (screen, print, Excel, PDF) come from one
 * table built once.
 */
final class ReportController extends Controller
{
    private function reports(): Reports
    {
        if ($this->auth()->year() === null) {
            throw new RedirectException('/years', 'app.errors.no_year');
        }
        return new Reports($this->db(), $this->auth(), new AccessScope($this->db(), $this->auth()));
    }

    public function index(Request $request): Response
    {
        return $this->view('reports/index', ['available' => $this->reports()->available()]);
    }

    /** One method serves every report; the key comes from the route, never from user input. */
    public function __call(string $name, array $arguments): Response
    {
        $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        if (!isset(Reports::ALL[$key])) {
            throw new HttpException(404);
        }
        return $this->show($key, $arguments[0]);
    }

    private function show(string $key, Request $request): Response
    {
        $reports = $this->reports();
        if (!in_array($key, $reports->available(), true)) {
            throw new HttpException(403);
        }
        $filters = $reports->cleanFilters($key, $request->query);
        $table = $reports->build($key, $filters);
        $format = $request->str('format');

        $options = [];
        $chosen = [];
        foreach (Reports::ALL[$key][1] as $name) {
            $options[$name] = $reports->options($name);
            if (isset($filters[$name])) {
                $chosen[] = __('reports.filters.' . $name) . ': ' . $options[$name][$filters[$name]];
            }
        }
        $title = __('reports.names.' . $key);
        $meta = [
            $this->auth()->school()['name'] . ' – ' . $this->auth()->year()['name'],
            ...($chosen === [] ? [] : [implode(' | ', $chosen)]),
            __('reports.generated', ['date' => date('d/m/Y H:i'), 'user' => $this->auth()->user()['full_name']]),
        ];
        $data = [
            'key' => $key, 'title' => $title, 'meta' => $meta, 'table' => $table, 'options' => $options, 'filters' => $filters,
            'missing' => $reports->missing($key, $filters),
            'canExport' => can('reports.export'),
            'query' => http_build_query($filters),
        ];

        if (in_array($format, ['xlsx', 'pdf'], true)) {
            if (!can('reports.export')) {
                throw new HttpException(403);
            }
            if ($table['columns'] === []) {
                return $this->redirect("/reports/$key", null, 'reports.errors.nothing');
            }
            $this->audit('export', 'report', null, null, ['report' => $key, 'format' => $format, 'filters' => $filters, 'rows' => count($table['rows'])]);
            $name = $key . '_' . date('Y-m-d') . '.' . $format;
            return $format === 'xlsx'
                ? Response::download(Exporter::excel($title, $meta, $table), $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                : Response::download(Exporter::pdf(View::render('reports/pdf', $data, null)), $name, 'application/pdf');
        }
        if ($format === 'print') {
            return $this->view('reports/print', $data, 'layouts/print');
        }
        return $this->view('reports/show', $data);
    }
}
