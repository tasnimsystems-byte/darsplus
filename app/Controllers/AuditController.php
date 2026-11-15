<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;

/** Read-only. Entries of the active school; network users also see entries with no school. */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $scope = '(a.school_id = ?' . ($this->auth()->isNetwork() ? ' OR a.school_id IS NULL' : '') . ')';
        $where = $scope;
        $params = [$this->schoolId()];

        $filters = [
            'action' => mb_substr($request->str('action'), 0, 30),
            'entity' => mb_substr($request->str('entity'), 0, 60),
            'from' => $request->str('from'),
            'to' => $request->str('to'),
        ];
        if ($filters['action'] !== '') {
            $where .= ' AND a.action = ?';
            $params[] = $filters['action'];
        }
        if ($filters['entity'] !== '') {
            $where .= ' AND a.entity_type = ?';
            $params[] = $filters['entity'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) {
                $where .= " AND a.created_at $operator ?";
                $params[] = $filters[$key] . ($key === 'from' ? ' 00:00:00' : ' 23:59:59');
            } else {
                $filters[$key] = '';
            }
        }

        $perPage = (int) App::config('app.per_page');
        $page = $this->page($request);
        $total = (int) $this->db()->value("SELECT COUNT(*) FROM audit_logs a WHERE $where", $params);
        $rows = $this->db()->all(
            "SELECT a.id, a.action, a.entity_type, a.entity_id, a.ip_address, a.created_at, u.full_name, u.username
               FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
              WHERE $where ORDER BY a.id DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage]
        );

        return $this->view('audit/index', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters,
            // Filter choices come from the same scope as the rows — never from other schools.
            'actions' => $this->db()->column(
                "SELECT DISTINCT a.action FROM audit_logs a WHERE $scope ORDER BY a.action",
                [$this->schoolId()]
            ),
            'entities' => $this->db()->column(
                "SELECT DISTINCT a.entity_type FROM audit_logs a WHERE $scope ORDER BY a.entity_type",
                [$this->schoolId()]
            ),
        ]);
    }
}
