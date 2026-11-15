<?php

declare(strict_types=1);

use App\Controllers\AssignmentController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\CalendarController;
use App\Controllers\ClassController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\GradeLevelController;
use App\Controllers\NotificationController;
use App\Controllers\ProfileController;
use App\Controllers\RoleController;
use App\Controllers\RoomController;
use App\Controllers\SchoolController;
use App\Controllers\StudentController;
use App\Controllers\StudentImportController;
use App\Controllers\SubjectController;
use App\Controllers\TeacherController;
use App\Controllers\UserController;
use App\Controllers\UserScopeController;
use App\Controllers\YearController;
use App\Controllers\AchievementController;
use App\Controllers\AssessmentController;
use App\Controllers\AssessmentTypeController;
use App\Controllers\AttendanceController;
use App\Controllers\CurriculumController;
use App\Controllers\DomainController;
use App\Controllers\GoalController;
use App\Controllers\RiskController;
use App\Controllers\RiskCriterionController;
use App\Controllers\WorkPlanController;
use App\Controllers\InterventionController;
use App\Controllers\MeetingController;
use App\Controllers\ObservationController;
use App\Controllers\ParentContactController;
use App\Controllers\StaffDevController;
use App\Controllers\TaskController;
use App\Controllers\NetworkController;
use App\Controllers\ReportController;
use App\Core\Router;
use App\Reports\Reports;

/** @var Router $router */

// Guest
$router->get('/login', [AuthController::class, 'showLogin'], ['auth' => false]);
$router->post('/login', [AuthController::class, 'login'], ['auth' => false]);
$router->post('/locale', [ProfileController::class, 'locale'], ['auth' => false]);

// Authenticated, before a school is chosen / while a password change is forced
$router->post('/logout', [AuthController::class, 'logout'], ['school' => false, 'password' => true]);
$router->get('/password', [ProfileController::class, 'showPassword'], ['school' => false, 'password' => true]);
$router->post('/password', [ProfileController::class, 'updatePassword'], ['school' => false, 'password' => true]);
$router->get('/select-school', [AuthController::class, 'showSchools'], ['school' => false]);
$router->post('/select-school', [AuthController::class, 'selectSchool'], ['school' => false]);

$router->get('/', [DashboardController::class, 'index'], ['permission' => 'dashboard.view']);

$router->get('/notifications', [NotificationController::class, 'index']);
$router->post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
$router->post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

$router->get('/users', [UserController::class, 'index'], ['permission' => 'users.view']);
$router->get('/users/create', [UserController::class, 'create'], ['permission' => 'users.create']);
$router->post('/users', [UserController::class, 'store'], ['permission' => 'users.create']);
$router->get('/users/{id}/edit', [UserController::class, 'edit'], ['permission' => 'users.update']);
$router->post('/users/{id}', [UserController::class, 'update'], ['permission' => 'users.update']);
$router->post('/users/{id}/delete', [UserController::class, 'destroy'], ['permission' => 'users.delete']);
$router->post('/users/{id}/reset-password', [UserController::class, 'resetPassword'], ['permission' => 'users.update']);

$router->get('/roles', [RoleController::class, 'index'], ['permission' => 'roles.view']);
$router->get('/roles/create', [RoleController::class, 'create'], ['permission' => 'roles.create']);
$router->post('/roles', [RoleController::class, 'store'], ['permission' => 'roles.create']);
$router->get('/roles/{id}/edit', [RoleController::class, 'edit'], ['permission' => 'roles.update']);
$router->post('/roles/{id}', [RoleController::class, 'update'], ['permission' => 'roles.update']);
$router->post('/roles/{id}/delete', [RoleController::class, 'destroy'], ['permission' => 'roles.delete']);

$router->get('/schools', [SchoolController::class, 'index'], ['permission' => 'schools.view']);
$router->get('/schools/create', [SchoolController::class, 'create'], ['permission' => 'schools.create']);
$router->post('/schools', [SchoolController::class, 'store'], ['permission' => 'schools.create']);
$router->get('/schools/{id}/edit', [SchoolController::class, 'edit'], ['permission' => 'schools.update']);
$router->post('/schools/{id}', [SchoolController::class, 'update'], ['permission' => 'schools.update']);

$router->get('/audit', [AuditController::class, 'index'], ['permission' => 'audit.view']);

$router->get('/documents', [DocumentController::class, 'index'], ['permission' => 'documents.view']);
$router->post('/documents', [DocumentController::class, 'store'], ['permission' => 'documents.create']);
$router->get('/documents/{id}/download', [DocumentController::class, 'download'], ['permission' => 'documents.view']);
$router->post('/documents/{id}/delete', [DocumentController::class, 'destroy'], ['permission' => 'documents.view']);

// ---- Stage C: school structure ------------------------------------------------

$router->post('/year', [ProfileController::class, 'year']);

/** list / create / store / edit / update / delete for a CrudController. */
$crud = static function (string $path, string $controller, string $module) use ($router): void {
    $router->get($path, [$controller, 'index'], ['permission' => "$module.view"]);
    $router->get("$path/create", [$controller, 'create'], ['permission' => "$module.create"]);
    $router->post($path, [$controller, 'store'], ['permission' => "$module.create"]);
    $router->get("$path/{id}/edit", [$controller, 'edit'], ['permission' => "$module.update"]);
    $router->post("$path/{id}", [$controller, 'update'], ['permission' => "$module.update"]);
    $router->post("$path/{id}/delete", [$controller, 'destroy'], ['permission' => "$module.delete"]);
};

$crud('/years', YearController::class, 'structure');
$router->get('/years/{id}', [YearController::class, 'show'], ['permission' => 'structure.view']);
$router->post('/years/{id}/current', [YearController::class, 'makeCurrent'], ['permission' => 'structure.update']);
$router->post('/years/{id}/lock', [YearController::class, 'toggleLock'], ['permission' => 'structure.update']);
$router->post('/years/{id}/terms', [YearController::class, 'storeTerm'], ['permission' => 'structure.update']);
$router->post('/years/{id}/terms/{term}/delete', [YearController::class, 'destroyTerm'], ['permission' => 'structure.update']);
$router->get('/years/{id}/transition', [YearController::class, 'showTransition'], ['permission' => 'structure.create']);
$router->post('/years/{id}/transition', [YearController::class, 'transition'], ['permission' => 'structure.create']);

$crud('/grade-levels', GradeLevelController::class, 'structure');
$crud('/subjects', SubjectController::class, 'structure');
$crud('/rooms', RoomController::class, 'structure');
$crud('/calendar', CalendarController::class, 'structure');

$crud('/classes', ClassController::class, 'structure');
$router->get('/classes/{id}', [ClassController::class, 'show'], ['permission' => 'structure.view']);
$router->post('/assignments', [AssignmentController::class, 'store'], ['permission' => 'structure.update']);
$router->post('/assignments/{id}/delete', [AssignmentController::class, 'destroy'], ['permission' => 'structure.update']);

$crud('/teachers', TeacherController::class, 'teachers');
$router->get('/teachers/{id}', [TeacherController::class, 'show'], ['permission' => 'teachers.view']);
$router->post('/teachers/{id}/positions', [TeacherController::class, 'storePosition'], ['permission' => 'teachers.update']);
$router->post('/teachers/{id}/positions/{position}/delete', [TeacherController::class, 'destroyPosition'], ['permission' => 'teachers.update']);

$router->get('/students/import', [StudentImportController::class, 'form'], ['permission' => 'students.create']);
$router->post('/students/import', [StudentImportController::class, 'preview'], ['permission' => 'students.create']);
$router->post('/students/import/confirm', [StudentImportController::class, 'confirm'], ['permission' => 'students.create']);
$router->post('/students/import/cancel', [StudentImportController::class, 'cancel'], ['permission' => 'students.create']);
$crud('/students', StudentController::class, 'students');
$router->get('/students/{id}', [StudentController::class, 'show'], ['permission' => 'students.view']);

$router->get('/users/{id}/scopes', [UserScopeController::class, 'index'], ['permission' => 'users.update']);
$router->post('/users/{id}/scopes', [UserScopeController::class, 'store'], ['permission' => 'users.update']);
$router->post('/users/{id}/scopes/{scope}/delete', [UserScopeController::class, 'destroy'], ['permission' => 'users.update']);

// ---- Stage D: pedagogy ----------------------------------------------------------

$crud('/domains', DomainController::class, 'work_plan');
$router->get('/work-plan', [WorkPlanController::class, 'index'], ['permission' => 'work_plan.view']);
$router->post('/work-plan', [WorkPlanController::class, 'saveVision'], ['permission' => 'work_plan.update']);
$router->post('/work-plan/approve', [WorkPlanController::class, 'approve'], ['permission' => 'work_plan.approve']);
$router->post('/work-plan/objectives', [WorkPlanController::class, 'storeObjective'], ['permission' => 'work_plan.update']);
$router->post('/work-plan/objectives/{id}/delete', [WorkPlanController::class, 'destroyObjective'], ['permission' => 'work_plan.update']);

$crud('/goals', GoalController::class, 'work_plan');
$router->get('/goals/{id}', [GoalController::class, 'show'], ['permission' => 'work_plan.view']);
$router->post('/goals/{id}/indicators', [GoalController::class, 'storeIndicator'], ['permission' => 'work_plan.update']);
$router->post('/goals/{id}/indicators/{indicator}', [GoalController::class, 'updateIndicator'], ['permission' => 'work_plan.update']);
$router->post('/goals/{id}/indicators/{indicator}/delete', [GoalController::class, 'destroyIndicator'], ['permission' => 'work_plan.update']);
$router->post('/goals/{id}/actions', [GoalController::class, 'storeAction'], ['permission' => 'work_plan.update']);
$router->post('/goals/{id}/actions/{action}/toggle', [GoalController::class, 'toggleAction'], ['permission' => 'work_plan.update']);
$router->post('/goals/{id}/actions/{action}/delete', [GoalController::class, 'destroyAction'], ['permission' => 'work_plan.update']);
$router->post('/goals/{id}/updates', [GoalController::class, 'storeUpdate'], ['permission' => 'work_plan.update']);

$crud('/curriculum', CurriculumController::class, 'curriculum');
$router->get('/curriculum/{id}', [CurriculumController::class, 'show'], ['permission' => 'curriculum.view']);
$router->post('/curriculum/{id}/approve', [CurriculumController::class, 'approve'], ['permission' => 'curriculum.approve']);
$router->post('/curriculum/{id}/units', [CurriculumController::class, 'storeUnit'], ['permission' => 'curriculum.update']);
$router->post('/curriculum/{id}/units/{unit}/delete', [CurriculumController::class, 'destroyUnit'], ['permission' => 'curriculum.update']);
$router->post('/curriculum/{id}/topics', [CurriculumController::class, 'storeTopic'], ['permission' => 'curriculum.update']);
$router->get('/curriculum/{id}/topics/{topic}/edit', [CurriculumController::class, 'editTopic'], ['permission' => 'curriculum.update']);
$router->post('/curriculum/{id}/topics/{topic}', [CurriculumController::class, 'updateTopic'], ['permission' => 'curriculum.update']);
$router->post('/curriculum/{id}/topics/{topic}/delete', [CurriculumController::class, 'destroyTopic'], ['permission' => 'curriculum.update']);
$router->get('/curriculum/{id}/progress/{assignment}', [CurriculumController::class, 'progress'], ['permission' => 'curriculum.view']);
$router->post('/curriculum/{id}/progress/{assignment}', [CurriculumController::class, 'saveProgress'], ['permission' => 'curriculum.update']);

$crud('/assessment-types', AssessmentTypeController::class, 'assessments');
$crud('/assessments', AssessmentController::class, 'assessments');
$router->get('/assessments/{id}', [AssessmentController::class, 'show'], ['permission' => 'assessments.view']);
$router->post('/assessments/{id}/results', [AssessmentController::class, 'saveResults'], ['permission' => 'assessments.update']);
$router->post('/assessments/{id}/publish', [AssessmentController::class, 'publish'], ['permission' => 'assessments.update']);
$router->get('/achievements', [AchievementController::class, 'index'], ['permission' => 'assessments.view']);

$router->get('/attendance', [AttendanceController::class, 'index'], ['permission' => 'attendance.view']);
$router->get('/attendance/report', [AttendanceController::class, 'report'], ['permission' => 'attendance.view']);
$router->post('/attendance/sessions', [AttendanceController::class, 'store'], ['permission' => 'attendance.create']);
$router->get('/attendance/sessions/{id}', [AttendanceController::class, 'show'], ['permission' => 'attendance.view']);
$router->post('/attendance/sessions/{id}', [AttendanceController::class, 'save'], ['permission' => 'attendance.update']);
$router->post('/attendance/sessions/{id}/cancel', [AttendanceController::class, 'toggleCancel'], ['permission' => 'attendance.update']);

$crud('/risk/criteria', RiskCriterionController::class, 'risk');
$router->get('/risk', [RiskController::class, 'index'], ['permission' => 'risk.view']);
$router->post('/risk/run', [RiskController::class, 'run'], ['permission' => 'risk.create']);
$router->get('/risk/{id}', [RiskController::class, 'show'], ['permission' => 'risk.view']);
$router->post('/risk/{id}', [RiskController::class, 'review'], ['permission' => 'risk.update']);

// ---- Stage E: workflows ------------------------------------------------------------

$crud('/interventions', InterventionController::class, 'interventions');
$router->get('/interventions/{id}', [InterventionController::class, 'show'], ['permission' => 'interventions.view']);
$router->post('/interventions/{id}/actions', [InterventionController::class, 'storeAction'], ['permission' => 'interventions.update']);
$router->post('/interventions/{id}/actions/{action}/toggle', [InterventionController::class, 'toggleAction'], ['permission' => 'interventions.update']);
$router->post('/interventions/{id}/actions/{action}/delete', [InterventionController::class, 'destroyAction'], ['permission' => 'interventions.update']);
$router->post('/interventions/{id}/reviews', [InterventionController::class, 'storeReview'], ['permission' => 'interventions.update']);

$crud('/parent-contacts', ParentContactController::class, 'parent_contacts');
$router->get('/parent-contacts/{id}', [ParentContactController::class, 'show'], ['permission' => 'parent_contacts.view']);
$router->get('/parent-contacts/{id}/summary', [ParentContactController::class, 'summary'], ['permission' => 'parent_contacts.view']);
$router->post('/students/{id}/guardians', [ParentContactController::class, 'storeGuardian'], ['permission' => 'parent_contacts.update']);
$router->post('/students/{id}/guardians/{guardian}/delete', [ParentContactController::class, 'destroyGuardian'], ['permission' => 'parent_contacts.update']);

$crud('/observations', ObservationController::class, 'observations');
$router->get('/observations/{id}', [ObservationController::class, 'show'], ['permission' => 'observations.view']);
$router->post('/observations/{id}/recommendations', [ObservationController::class, 'storeRecommendation'], ['permission' => 'observations.update']);
$router->post('/observations/{id}/recommendations/{recommendation}', [ObservationController::class, 'updateRecommendation'], ['permission' => 'observations.update']);

$router->get('/my-development', [StaffDevController::class, 'mine'], ['permission' => 'staff_dev.view']);
$router->get('/staff-development/{id}', [StaffDevController::class, 'show'], ['permission' => 'staff_dev.view']);
$router->post('/staff-development/{id}/goals', [StaffDevController::class, 'storeGoal'], ['permission' => 'staff_dev.update']);
$router->post('/staff-development/{id}/goals/{goal}', [StaffDevController::class, 'updateGoal'], ['permission' => 'staff_dev.update']);
$router->post('/staff-development/{id}/goals/{goal}/delete', [StaffDevController::class, 'destroyGoal'], ['permission' => 'staff_dev.update']);
$router->post('/staff-development/{id}/trainings', [StaffDevController::class, 'storeTraining'], ['permission' => 'staff_dev.update']);
$router->post('/staff-development/{id}/trainings/{training}/delete', [StaffDevController::class, 'destroyTraining'], ['permission' => 'staff_dev.update']);

$crud('/meetings', MeetingController::class, 'meetings');
$router->get('/meetings/{id}', [MeetingController::class, 'show'], ['permission' => 'meetings.view']);
$router->post('/meetings/{id}/minutes', [MeetingController::class, 'saveMinutes'], ['permission' => 'meetings.update']);
$router->post('/meetings/{id}/decisions', [MeetingController::class, 'storeDecision'], ['permission' => 'meetings.update']);
// Marking a decision done is open to its owner, so it needs only view permission; the controller checks the person.
$router->post('/meetings/{id}/decisions/{decision}/toggle', [MeetingController::class, 'toggleDecision'], ['permission' => 'meetings.view']);
$router->post('/meetings/{id}/decisions/{decision}/delete', [MeetingController::class, 'destroyDecision'], ['permission' => 'meetings.update']);

$router->get('/tasks/board', [TaskController::class, 'board'], ['permission' => 'tasks.view']);
$crud('/tasks', TaskController::class, 'tasks');
$router->get('/tasks/{id}', [TaskController::class, 'show'], ['permission' => 'tasks.view']);
$router->post('/tasks/{id}/status', [TaskController::class, 'setStatus'], ['permission' => 'tasks.update']);
$router->post('/tasks/{id}/comments', [TaskController::class, 'storeComment'], ['permission' => 'tasks.view']);

// ---- Stage F: reports ---------------------------------------------------------------

$router->get('/reports', [ReportController::class, 'index'], ['permission' => 'reports.view']);
foreach (array_keys(Reports::ALL) as $reportKey) {
    $router->get("/reports/$reportKey", [ReportController::class, lcfirst(str_replace('_', '', ucwords($reportKey, '_')))], ['permission' => 'reports.view']);
}

// ---- Network overview (network-level users with reports.view) --------------------
$router->get('/network', [NetworkController::class, 'index'], ['permission' => 'reports.view']);
