<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AttendanceStats;
use App\Services\GoalService;
use App\Services\GradeStats;

/**
 * Network overview: the schools side by side, each in its own current year.
 *
 * Only for network-level users who also hold reports.view (the network
 * supervisor). The technical system administrator is network-level but has no
 * reports permission, so pedagogical figures stay out of reach for that role.
 * Only aggregates are shown — counts, averages and rates; no student or
 * teacher appears by name.
 */
final class NetworkController extends Controller
{
    public function index(Request $request): Response
    {
        if (!$this->auth()->isNetwork()) {
            throw new HttpException(403);
        }
        $db = $this->db();
        $everything = ['1 = 1', []];
        $rows = [];
        foreach ($db->all('SELECT id, name, code FROM schools WHERE is_active = 1 AND deleted_at IS NULL ORDER BY name') as $school) {
            $schoolId = (int) $school['id'];
            $year = $db->one('SELECT id, name FROM academic_years WHERE school_id = ? AND is_current = 1 LIMIT 1', [$schoolId]);
            $row = ['school' => $school, 'year' => $year['name'] ?? null, 'students' => null, 'classes' => null,
                'average' => null, 'graded' => 0, 'attendance' => null, 'goals' => null, 'delayed' => null, 'indications' => null];
            if ($year !== null) {
                $yearId = (int) $year['id'];
                $row['students'] = (int) $db->value(
                    "SELECT COUNT(DISTINCT s.id) FROM students s
                       JOIN class_enrollments e ON e.student_id = s.id AND e.left_on IS NULL
                       JOIN classes c ON c.id = e.class_id AND c.academic_year_id = ?
                      WHERE s.school_id = ? AND s.deleted_at IS NULL AND s.status = 'active'",
                    [$yearId, $schoolId]
                );
                $row['classes'] = (int) $db->value('SELECT COUNT(*) FROM classes WHERE academic_year_id = ?', [$yearId]);
                $overall = (new GradeStats($db, $schoolId, $yearId, $everything))->overall();
                $row['average'] = $overall['average'];
                $row['graded'] = $overall['students'];
                $row['attendance'] = AttendanceStats::rate($db, $schoolId, $yearId, $everything);
                $goals = GoalService::summary($db->all(
                    'SELECT status, end_date, progress_percent FROM annual_goals WHERE school_id = ? AND academic_year_id = ? AND deleted_at IS NULL',
                    [$schoolId, $yearId]
                ));
                $row['goals'] = $goals['average_progress'];
                $row['delayed'] = $goals['delayed'];
                $row['indications'] = (int) $db->value(
                    "SELECT COUNT(DISTINCT ri.student_id) FROM risk_indications ri JOIN academic_terms tm ON tm.id = ri.term_id
                      WHERE ri.school_id = ? AND tm.academic_year_id = ? AND ri.review_status <> 'dismissed'",
                    [$schoolId, $yearId]
                );
            }
            $rows[] = $row;
        }
        $this->audit('view', 'network_overview');
        return $this->view('network/index', ['rows' => $rows]);
    }
}
