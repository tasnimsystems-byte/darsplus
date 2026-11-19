<?php

declare(strict_types=1);

/**
 * Permission = module.action. Every module × action pair is created by BaseSeeder.
 *
 * Role defaults use one letter per action:
 *   v=view  c=create  u=update  d=delete  a=approve  e=export
 * 'scope' => 'all' lets holders see the whole school; otherwise they see only the
 * classes they teach, lead or were explicitly given (see App\Services\AccessScope).
 * They are applied only when a system role is first created; later edits made
 * in the roles screen are never overwritten by re-seeding.
 */
return [
    'actions' => ['v' => 'view', 'c' => 'create', 'u' => 'update', 'd' => 'delete', 'a' => 'approve', 'e' => 'export'],

    'modules' => [
        'dashboard', 'users', 'roles', 'schools', 'settings', 'audit',
        'structure', 'students', 'teachers',
        'work_plan', 'curriculum', 'assessments', 'attendance', 'risk', 'interventions',
        'staff_dev', 'observations', 'meetings', 'tasks', 'parent_contacts',
        'documents', 'reports', 'portal',
    ],

    'roles' => [
        // Network level (user_roles.school_id IS NULL)
        'system_admin' => ['level' => 'network', 'scope' => 'all', 'grants' => [
            // Technical administration only — deliberately no access to pedagogical data.
            'dashboard' => 'v', 'users' => 'vcud', 'roles' => 'vcud', 'schools' => 'vcud',
            'settings' => 'vu', 'audit' => 've', 'structure' => 'vcud',
        ]],
        'network_supervisor' => ['level' => 'network', 'grants' => [
            'dashboard' => 'v', 'schools' => 'v', 'reports' => 've',
        ]],

        // School level
        'principal' => ['level' => 'school', 'scope' => 'all', 'grants' => [
            'dashboard' => 'v', 'users' => 'vcud', 'roles' => 'v', 'schools' => 'v', 'settings' => 'vu', 'audit' => 'v',
            'structure' => 'vcud', 'students' => 'vcude', 'teachers' => 'vcude',
            'work_plan' => 'vcudae', 'curriculum' => 'vcudae', 'assessments' => 'vcudae', 'attendance' => 'vcude',
            'risk' => 'vcud', 'interventions' => 'vcudae', 'staff_dev' => 'vcud', 'observations' => 'vcud',
            'meetings' => 'vcudae', 'tasks' => 'vcud', 'parent_contacts' => 'vcud',
            'documents' => 'vcud', 'reports' => 've',
        ]],
        'vice_principal' => ['level' => 'school', 'scope' => 'all', 'grants' => [
            'dashboard' => 'v', 'users' => 'v', 'schools' => 'v',
            'structure' => 'vcu', 'students' => 'vcue', 'teachers' => 'vue',
            'work_plan' => 'vcude', 'curriculum' => 'vcudae', 'assessments' => 'vcude', 'attendance' => 'vcude',
            'risk' => 'vcud', 'interventions' => 'vcude', 'staff_dev' => 'vcud', 'observations' => 'vcud',
            'meetings' => 'vcude', 'tasks' => 'vcud', 'parent_contacts' => 'vcud',
            'documents' => 'vcud', 'reports' => 've',
        ]],
        'pedagogical_coordinator' => ['level' => 'school', 'scope' => 'all', 'grants' => [
            'dashboard' => 'v', 'structure' => 'v', 'students' => 'v', 'teachers' => 'v',
            'work_plan' => 'vcu', 'curriculum' => 'vcua', 'assessments' => 've', 'attendance' => 've',
            'risk' => 'vcu', 'interventions' => 'vcud', 'staff_dev' => 'vcu', 'observations' => 'vcud',
            'meetings' => 'vcud', 'tasks' => 'vcud', 'parent_contacts' => 'v',
            'documents' => 'vcud', 'reports' => 've',
        ]],
        'subject_coordinator' => ['level' => 'school', 'grants' => [
            'dashboard' => 'v', 'structure' => 'v', 'students' => 'v', 'teachers' => 'v',
            'work_plan' => 'vu', 'curriculum' => 'vcu', 'assessments' => 've', 'attendance' => 'v',
            'risk' => 'v', 'observations' => 'vcu', 'meetings' => 'vcu', 'tasks' => 'vcu',
            'documents' => 'vc', 'reports' => 've',
        ]],
        'grade_coordinator' => ['level' => 'school', 'grants' => [
            'dashboard' => 'v', 'structure' => 'v', 'students' => 'v', 'teachers' => 'v',
            'work_plan' => 'vu', 'curriculum' => 'v', 'assessments' => 've', 'attendance' => 've',
            'risk' => 'vu', 'interventions' => 'vcu', 'meetings' => 'vcu', 'tasks' => 'vcu',
            'parent_contacts' => 'vcu', 'documents' => 'vc', 'reports' => 've',
        ]],
        'homeroom_teacher' => ['level' => 'school', 'grants' => [
            'dashboard' => 'v', 'structure' => 'v', 'students' => 'v',
            'work_plan' => 'v', 'curriculum' => 'v', 'assessments' => 'vcu', 'attendance' => 'vcu',
            'risk' => 'vu', 'interventions' => 'vcu', 'staff_dev' => 'v', 'observations' => 'v',
            'meetings' => 'v', 'tasks' => 'vcu', 'parent_contacts' => 'vcu',
            'documents' => 'vc', 'reports' => 'v',
        ]],
        'subject_teacher' => ['level' => 'school', 'grants' => [
            'dashboard' => 'v', 'structure' => 'v', 'students' => 'v',
            'work_plan' => 'v', 'curriculum' => 'vu', 'assessments' => 'vcud', 'attendance' => 'vcu',
            'risk' => 'v', 'interventions' => 'vu', 'staff_dev' => 'v', 'observations' => 'v',
            'meetings' => 'v', 'tasks' => 'vcu', 'parent_contacts' => 'vc',
            'documents' => 'vc',
        ]],
        'counselor' => ['level' => 'school', 'scope' => 'all', 'grants' => [
            'dashboard' => 'v', 'structure' => 'v', 'students' => 'v',
            'work_plan' => 'v', 'assessments' => 'v', 'attendance' => 'v',
            'risk' => 'vcu', 'interventions' => 'vcud', 'meetings' => 'vcu', 'tasks' => 'vcu',
            'parent_contacts' => 'vcud', 'documents' => 'vc', 'reports' => 'v',
        ]],
        'viewer' => ['level' => 'school', 'grants' => [
            'dashboard' => 'v', 'work_plan' => 'v', 'reports' => 'v',
        ]],

        // Portal: their own data only (App\Services\Portal), nothing of the staff modules.
        'student' => ['level' => 'school', 'grants' => ['portal' => 'v']],
        'parent' => ['level' => 'school', 'grants' => ['portal' => 'v']],
    ],
];
