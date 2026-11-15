<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Services\AccessScope;
use App\Services\UserService;

/**
 * Manual access scopes: widen what one user sees beyond what their teaching
 * assignments and positions already give them. Granting requires that the
 * person granting can see the whole school themselves.
 */
final class UserScopeController extends Controller
{
    private const TABLES = ['grade_level' => 'grade_levels', 'class' => 'classes', 'subject' => 'subjects'];

    private function target(int $userId): array
    {
        $users = new UserRepository($this->db());
        $service = new UserService($this->db(), $this->auth(), $users, new RoleRepository($this->db()));
        $user = $service->findManageable($userId, $this->schoolId());
        if (!(new AccessScope($this->db(), $this->auth()))->isUnrestricted()) {
            throw new HttpException(403);
        }
        return $user;
    }

    public function index(Request $request, int $id): Response
    {
        $user = $this->target($id);
        $schoolId = $this->schoolId();
        $yearId = (int) ($this->auth()->year()['id'] ?? 0);
        $options = static fn (array $rows): array => array_column($rows, 'label', 'id');

        return $this->view('users/scopes', [
            'user' => $user,
            'year' => $this->auth()->year(),
            'scopes' => $this->db()->all(
                "SELECT us.id, us.scope_type, us.academic_year_id,
                        COALESCE(gl.name, c.name, s.name) AS target_name
                   FROM user_scopes us
                   LEFT JOIN grade_levels gl ON us.scope_type = 'grade_level' AND gl.id = us.scope_id
                   LEFT JOIN classes c ON us.scope_type = 'class' AND c.id = us.scope_id
                   LEFT JOIN subjects s ON us.scope_type = 'subject' AND s.id = us.scope_id
                  WHERE us.user_id = ? AND us.school_id = ? AND (us.academic_year_id IS NULL OR us.academic_year_id = ?)
                  ORDER BY us.id",
                [$id, $schoolId, $yearId]
            ),
            'targets' => [
                'grade_level' => $options($this->db()->all('SELECT id, name AS label FROM grade_levels WHERE school_id = ? ORDER BY level_number', [$schoolId])),
                'class' => $options($this->db()->all('SELECT id, name AS label FROM classes WHERE school_id = ? AND academic_year_id = ? ORDER BY name', [$schoolId, $yearId])),
                'subject' => $options($this->db()->all('SELECT id, name AS label FROM subjects WHERE school_id = ? ORDER BY name', [$schoolId])),
            ],
        ]);
    }

    public function store(Request $request, int $id): Response
    {
        $this->target($id);
        $schoolId = $this->schoolId();
        $year = $this->auth()->year();
        $type = $request->str('scope_type');
        $back = "/users/$id/scopes";
        if ($year === null) {
            return $this->redirect($back, null, 'app.errors.no_year');
        }

        $scopeId = null;
        if ($type !== 'all') {
            $table = self::TABLES[$type] ?? throw new HttpException(403);
            $scopeId = $request->int('scope_id');
            $extra = $type === 'class' ? ' AND academic_year_id = ' . (int) $year['id'] : '';
            if ($this->db()->value("SELECT 1 FROM `$table` WHERE id = ? AND school_id = ?$extra", [$scopeId, $schoolId]) === null) {
                return $this->redirect($back, null, 'scopes.errors.target');
            }
        }
        $exists = $this->db()->value(
            'SELECT 1 FROM user_scopes WHERE user_id = ? AND school_id = ? AND academic_year_id = ? AND scope_type = ? AND scope_id <=> ?',
            [$id, $schoolId, $year['id'], $type, $scopeId]
        );
        if ($exists === null) {
            $row = [
                'user_id' => $id, 'school_id' => $schoolId, 'academic_year_id' => (int) $year['id'],
                'scope_type' => $type, 'scope_id' => $scopeId, 'created_by' => $this->auth()->id(),
            ];
            $scopeRowId = $this->db()->insert('user_scopes', $row);
            $this->audit('create', 'user_scope', $scopeRowId, null, $row);
        }
        return $this->redirect($back, 'app.created');
    }

    public function destroy(Request $request, int $id, int $scope): Response
    {
        $this->target($id);
        $row = $this->db()->one(
            'SELECT * FROM user_scopes WHERE id = ? AND user_id = ? AND school_id = ?',
            [$scope, $id, $this->schoolId()]
        ) ?? throw new HttpException(404);
        $this->db()->run('DELETE FROM user_scopes WHERE id = ?', [$scope]);
        $this->audit('delete', 'user_scope', $scope, ['scope_type' => $row['scope_type'], 'scope_id' => $row['scope_id']], null);
        return $this->redirect("/users/$id/scopes", 'app.deleted');
    }
}
