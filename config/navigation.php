<?php

declare(strict_types=1);

/**
 * Sidebar entries. Only modules that exist are listed — an entry is added here
 * in the same change that adds its routes, so there are never dead links.
 */
return [
    ['group' => 'nav.group_main', 'items' => [
        ['path' => '/', 'label' => 'nav.dashboard', 'icon' => 'speedometer2', 'permission' => 'dashboard.view'],
        ['path' => '/network', 'label' => 'nav.network', 'icon' => 'diagram-2', 'permission' => 'reports.view', 'network' => true],
        ['path' => '/reports', 'label' => 'nav.reports', 'icon' => 'file-earmark-bar-graph', 'permission' => 'reports.view'],
        ['path' => '/documents', 'label' => 'nav.documents', 'icon' => 'folder2-open', 'permission' => 'documents.view'],
    ]],
    ['group' => 'nav.group_school', 'items' => [
        ['path' => '/students', 'label' => 'nav.students', 'icon' => 'mortarboard', 'permission' => 'students.view'],
        ['path' => '/teachers', 'label' => 'nav.teachers', 'icon' => 'person-workspace', 'permission' => 'teachers.view'],
        ['path' => '/classes', 'label' => 'nav.classes', 'icon' => 'grid-3x3-gap', 'permission' => 'structure.view'],
        ['path' => '/calendar', 'label' => 'nav.calendar', 'icon' => 'calendar3', 'permission' => 'structure.view'],
    ]],
    ['group' => 'nav.group_pedagogy', 'items' => [
        ['path' => '/work-plan', 'label' => 'nav.work_plan', 'icon' => 'bullseye', 'permission' => 'work_plan.view'],
        ['path' => '/curriculum', 'label' => 'nav.curriculum', 'icon' => 'journal-bookmark', 'permission' => 'curriculum.view'],
        ['path' => '/assessments', 'label' => 'nav.assessments', 'icon' => 'clipboard-check', 'permission' => 'assessments.view'],
        ['path' => '/achievements', 'label' => 'nav.achievements', 'icon' => 'graph-up', 'permission' => 'assessments.view'],
        ['path' => '/attendance', 'label' => 'nav.attendance', 'icon' => 'person-check', 'permission' => 'attendance.view'],
        ['path' => '/risk', 'label' => 'nav.risk', 'icon' => 'flag', 'permission' => 'risk.view'],
    ]],
    ['group' => 'nav.group_work', 'items' => [
        ['path' => '/tasks', 'label' => 'nav.tasks', 'icon' => 'check2-square', 'permission' => 'tasks.view'],
        ['path' => '/meetings', 'label' => 'nav.meetings', 'icon' => 'calendar-event', 'permission' => 'meetings.view'],
        ['path' => '/interventions', 'label' => 'nav.interventions', 'icon' => 'life-preserver', 'permission' => 'interventions.view'],
        ['path' => '/parent-contacts', 'label' => 'nav.parent_contacts', 'icon' => 'telephone', 'permission' => 'parent_contacts.view'],
        ['path' => '/observations', 'label' => 'nav.observations', 'icon' => 'binoculars', 'permission' => 'observations.create'],
        ['path' => '/my-development', 'label' => 'nav.my_development', 'icon' => 'award', 'permission' => 'staff_dev.view'],
    ]],
    ['group' => 'nav.group_setup', 'items' => [
        ['path' => '/years', 'label' => 'nav.years', 'icon' => 'calendar-range', 'permission' => 'structure.update'],
        ['path' => '/grade-levels', 'label' => 'nav.grade_levels', 'icon' => 'layers', 'permission' => 'structure.update'],
        ['path' => '/subjects', 'label' => 'nav.subjects', 'icon' => 'book', 'permission' => 'structure.update'],
        ['path' => '/rooms', 'label' => 'nav.rooms', 'icon' => 'door-open', 'permission' => 'structure.update'],
        ['path' => '/assessment-types', 'label' => 'nav.assessment_types', 'icon' => 'tags', 'permission' => 'assessments.delete'],
        ['path' => '/domains', 'label' => 'nav.domains', 'icon' => 'diagram-3', 'permission' => 'work_plan.delete'],
    ]],
    ['group' => 'nav.group_admin', 'items' => [
        ['path' => '/users', 'label' => 'nav.users', 'icon' => 'people', 'permission' => 'users.view'],
        ['path' => '/roles', 'label' => 'nav.roles', 'icon' => 'shield-lock', 'permission' => 'roles.view'],
        ['path' => '/schools', 'label' => 'nav.schools', 'icon' => 'building', 'permission' => 'schools.view'],
        ['path' => '/audit', 'label' => 'nav.audit', 'icon' => 'journal-text', 'permission' => 'audit.view'],
    ]],
];
