<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\App;
use App\Core\Database;
use App\Services\Passwords;

/**
 * Fictional demonstration data — no real people or schools.
 * Two schools, their current academic year, one user per typical role, and the
 * school structure (DemoStructureSeeder). Later stages add pedagogical data.
 *
 * All demo users share the password in DEMO_PASSWORD (.env). Without it a
 * random password is generated and returned once.
 */
final class DemoSeeder
{
    public function __construct(private Database $db)
    {
    }

    /** @return array{password_generated: ?string, users: string[]} */
    public function run(): array
    {
        if (!in_array(App::config('app.env'), ['local', 'testing'], true)) {
            throw new \RuntimeException('Demo data may only be seeded when APP_ENV is local or testing.');
        }
        if ((int) $this->db->value("SELECT COUNT(*) FROM schools WHERE code IN ('DEMO-HE', 'DEMO-AR')") > 0) {
            throw new \RuntimeException('Demo data already exists.');
        }

        $generated = null;
        $password = (string) env('DEMO_PASSWORD', '');
        if ($password === '') {
            $password = $generated = Passwords::generate();
        }
        $hash = Passwords::hash($password);
        $roleIds = array_column($this->db->all('SELECT id, code FROM roles WHERE is_system = 1'), 'id', 'code');
        $users = [];

        $this->db->transaction(function () use ($hash, $roleIds, &$users): void {
            $userIds = [];
            $schools = [
                'he' => $this->db->insert('schools', ['name' => 'חטיבת הביניים "אופק" (הדגמה)', 'code' => 'DEMO-HE', 'default_locale' => 'he']),
                'ar' => $this->db->insert('schools', ['name' => 'إعدادية "الأفق" (تجريبية)', 'code' => 'DEMO-AR', 'default_locale' => 'ar']),
            ];
            foreach ($schools as $locale => $schoolId) {
                $this->db->insert('academic_years', [
                    'school_id' => $schoolId, 'name' => $locale === 'ar' ? '2026-2027' : 'תשפ"ז', 'start_date' => '2026-09-01',
                    'end_date' => '2027-06-30', 'is_current' => 1,
                ]);
            }

            // [username, full name, locale, [[role, school key|null], ...]]
            $accounts = [
                ['demo.admin', 'מנהל מערכת (הדגמה)', 'he', [['system_admin', null]]],
                ['demo.supervisor', 'מפקחת רשת (הדגמה)', 'he', [['network_supervisor', null]]],
                ['demo.principal', 'דנה לוי-הדגמה', 'he', [['principal', 'he']]],
                ['demo.vice', 'יואב כהן-הדגמה', 'he', [['vice_principal', 'he']]],
                ['demo.coordinator', 'מיכל ברק-הדגמה', 'he', [['pedagogical_coordinator', 'he']]],
                ['demo.teacher', 'נועה שגיא-הדגמה', 'he', [['subject_teacher', 'he'], ['homeroom_teacher', 'he']]],
                ['demo.counselor', 'תמר אלון-הדגמה', 'he', [['counselor', 'he']]],
                ['demo.viewer', 'צופה (הדגמה)', 'he', [['viewer', 'he']]],
                ['demo.principal.ar', 'سامر خوري (تجريبي)', 'ar', [['principal', 'ar']]],
                ['demo.teacher.ar', 'ريم حداد (تجريبية)', 'ar', [['subject_teacher', 'ar']]],
                // One person teaching in both schools — exercises the school switcher.
                ['demo.shared', 'עדי נאסר-הדגמה', 'he', [['subject_teacher', 'he'], ['subject_teacher', 'ar']]],
            ];
            foreach ($accounts as [$username, $name, $locale, $roles]) {
                $userId = $this->db->insert('users', [
                    'username' => $username, 'full_name' => $name, 'locale' => $locale, 'password_hash' => $hash,
                ]);
                foreach ($roles as [$role, $schoolKey]) {
                    $this->db->insert('user_roles', [
                        'user_id' => $userId, 'role_id' => $roleIds[$role],
                        'school_id' => $schoolKey === null ? null : $schools[$schoolKey],
                    ]);
                }
                $users[] = $username;
                $userIds[$username] = $userId;
            }

            foreach ($schools as $locale => $schoolId) {
                \App\Services\SchoolDefaults::ensure($this->db, $schoolId, $locale);
            }
            (new DemoStructureSeeder($this->db))->run($schools, [
                'he' => ['teacher' => $userIds['demo.teacher'], 'coordinator' => $userIds['demo.shared']],
                'ar' => ['teacher' => $userIds['demo.teacher.ar']],
            ]);
            (new DemoPedagogySeeder($this->db))->run($schools, [
                'he' => $userIds['demo.coordinator'], 'ar' => $userIds['demo.principal.ar'],
            ]);
            (new DemoWorkflowSeeder($this->db))->run([
                'he' => ['school' => $schools['he'], 'lead' => $userIds['demo.principal'],
                    'staff' => [$userIds['demo.teacher'], $userIds['demo.coordinator'], $userIds['demo.counselor']]],
                'ar' => ['school' => $schools['ar'], 'lead' => $userIds['demo.principal.ar'], 'staff' => [$userIds['demo.teacher.ar']]],
            ]);
        });

        return ['password_generated' => $generated, 'users' => $users];
    }
}
