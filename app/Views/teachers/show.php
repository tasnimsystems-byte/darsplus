<?php
use App\Controllers\TeacherController;
use App\Core\View;

$name = $teacher['last_name'] . ' ' . $teacher['first_name'];
View::share('title', $name);
$id = (int) $teacher['id'];
$typeOptions = [];
foreach (TeacherController::POSITION_TYPES as $type) {
    $typeOptions[$type] = __('teachers.position_types.' . $type);
}
$totalHours = array_sum(array_map(static fn ($a) => (float) $a['weekly_hours'], $assignments));
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($name) ?></h1>
        <p class="text-secondary mb-0">
            <?php if (!$teacher['is_active']): ?><span class="badge text-bg-secondary"><?= e(__('app.inactive')) ?></span><?php endif; ?>
            <?= e(__('teachers.fields.subject_ids')) ?>: <?= e($subjects === [] ? '—' : implode(', ', $subjects)) ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($assignments !== [] && can('structure.view')): ?>
            <a class="btn btn-outline-primary" href="<?= e(url('/timetable?view=teacher&id=' . $id)) ?>"><i class="bi bi-table" aria-hidden="true"></i> <?= e(__('timetable.title')) ?></a>
        <?php endif; ?>
        <?php if (can('staff_dev.view')): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/staff-development/$id")) ?>"><i class="bi bi-award" aria-hidden="true"></i> <?= e(__('staffdev.title')) ?></a>
        <?php endif; ?>
        <?php if (can('teachers.update')): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/teachers/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('teachers.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/teachers')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="row g-4">
    <?php if (can('teachers.update')): ?>
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('teachers.details')) ?></h2></div>
                <dl class="card-body mb-0">
                    <dt><?= e(__('teachers.fields.staff_code')) ?></dt><dd dir="ltr" class="text-end"><?= e($teacher['staff_code'] ?? '—') ?></dd>
                    <dt><?= e(__('teachers.fields.phone')) ?></dt><dd dir="ltr" class="text-end"><?= e($teacher['phone'] ?? '—') ?></dd>
                    <dt><?= e(__('teachers.fields.user_id')) ?></dt>
                    <dd class="mb-0"><?= $account ? e($account['full_name']) . ' <span dir="ltr" class="text-secondary">(' . e($account['username']) . ')</span>' : e(__('teachers.no_account')) ?></dd>
                </dl>
            </div>
        </div>
    <?php endif; ?>

    <div class="col-12 <?= can('teachers.update') ? 'col-lg-8' : '' ?>">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between">
                <h2 class="h6 mb-0"><?= e(__('teachers.assignments', ['year' => $year['name'] ?? '—'])) ?></h2>
                <?php if ($totalHours > 0): ?><span class="text-secondary small"><?= e(__('teachers.total_hours', ['hours' => format_hours($totalHours)])) ?></span><?php endif; ?>
            </div>
            <?php if ($homerooms !== []): ?>
                <div class="card-body border-bottom py-2">
                    <?= e(__('teachers.homeroom_of')) ?>
                    <?php foreach ($homerooms as $homeroom): ?>
                        <a class="badge text-bg-light" href="<?= e(url('/classes/' . (int) $homeroom['id'])) ?>"><?= e($homeroom['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($assignments === []): ?>
                <div class="card-body text-secondary"><?= e(__('teachers.no_assignments')) ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <caption class="visually-hidden"><?= e(__('teachers.assignments', ['year' => $year['name'] ?? ''])) ?></caption>
                        <thead><tr>
                            <th scope="col"><?= e(__('assignments.fields.class_id')) ?></th>
                            <th scope="col"><?= e(__('assignments.fields.subject_id')) ?></th>
                            <th scope="col"><?= e(__('assignments.fields.weekly_hours')) ?></th>
                            <th scope="col"><?= e(__('assignments.fields.group_name')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <th scope="row" class="fw-normal">
                                    <?php if (can('structure.view')): ?>
                                        <a href="<?= e(url('/classes/' . (int) $assignment['class_id'])) ?>"><?= e($assignment['class_name']) ?></a>
                                    <?php else: ?>
                                        <?= e($assignment['class_name']) ?>
                                    <?php endif; ?>
                                </th>
                                <td><?= e($assignment['subject_name']) ?></td>
                                <td><?= e(format_hours($assignment['weekly_hours'])) ?></td>
                                <td><?= e($assignment['group_name'] !== '' ? $assignment['group_name'] : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('teachers.positions', ['year' => $year['name'] ?? '—'])) ?></h2></div>
            <?php if ($positions === []): ?>
                <div class="card-body text-secondary"><?= e(__('teachers.no_positions')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($positions as $position): ?>
                        <?php $label = __('teachers.position_types.' . $position['position_type'])
                            . ($position['level_name'] ? ' – ' . $position['level_name'] : '')
                            . ($position['subject_name'] ? ' – ' . $position['subject_name'] : ''); ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="fw-semibold"><?= e($label) ?></div>
                                <?php if ($position['responsibilities']): ?><div class="small text-secondary"><?= nl2br(e($position['responsibilities'])) ?></div><?php endif; ?>
                            </div>
                            <?php if ($canEdit): ?>
                                <form method="post" action="<?= e(url("/teachers/$id/positions/" . (int) $position['id'] . '/delete')) ?>"
                                      data-confirm="<?= e(__('app.confirm_delete', ['name' => $label])) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $label])) ?>">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <div class="card-body border-top">
                    <h3 class="h6"><?= e(__('teachers.add_position')) ?></h3>
                    <p class="form-text"><?= e(__('teachers.position_scope_note')) ?></p>
                    <form class="row g-3" method="post" action="<?= e(url("/teachers/$id/positions")) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'position_type', 'label' => __('teachers.position_fields.position_type'), 'type' => 'select', 'required' => true, 'options' => ['' => __('app.select_placeholder')] + $typeOptions]) ?></div>
                        <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'grade_level_id', 'label' => __('teachers.position_fields.grade_level_id'), 'type' => 'select', 'options' => ['' => '—'] + $levels]) ?></div>
                        <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'subject_id', 'label' => __('teachers.position_fields.subject_id'), 'type' => 'select', 'options' => ['' => '—'] + $subjectOptions]) ?></div>
                        <div class="col-12 col-md-9"><?= View::partial('partials/input', ['name' => 'responsibilities', 'label' => __('teachers.position_fields.responsibilities'), 'attrs' => 'maxlength="2000"']) ?></div>
                        <div class="col-12 col-md-3 form-inline-action"><button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
