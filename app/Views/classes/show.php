<?php
use App\Core\View;

View::share('title', $class['name']);
$id = (int) $class['id'];
$canEdit = can('structure.update') && !$readOnly;
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('classes.class_named', ['name' => $class['name']])) ?></h1>
        <p class="text-secondary mb-0">
            <?= e($class['level_name']) ?>
            · <?= e(__('classes.fields.homeroom_teacher_id')) ?>: <?= e($class['homeroom_name'] ?? '—') ?>
            · <?= e(__('classes.fields.room_id')) ?>: <?= e($class['room_name'] ?? '—') ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/classes/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('classes.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/classes')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('classes.assignments')) ?></h2></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden"><?= e(__('classes.assignments')) ?></caption>
                    <thead>
                    <tr>
                        <th scope="col"><?= e(__('assignments.fields.subject_id')) ?></th>
                        <th scope="col"><?= e(__('assignments.fields.teacher_id')) ?></th>
                        <th scope="col"><?= e(__('assignments.fields.weekly_hours')) ?></th>
                        <th scope="col"><?= e(__('assignments.fields.group_name')) ?></th>
                        <?php if ($canEdit): ?><th scope="col" class="text-end"><?= e(__('app.actions')) ?></th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($assignments === []): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4"><?= e(__('classes.no_assignments')) ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <th scope="row" class="fw-normal"><?= e($assignment['subject_name']) ?></th>
                            <td>
                                <?php if (can('teachers.view')): ?>
                                    <a href="<?= e(url('/teachers/' . (int) $assignment['teacher_id'])) ?>"><?= e($assignment['teacher_name']) ?></a>
                                <?php else: ?>
                                    <?= e($assignment['teacher_name']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e(format_hours($assignment['weekly_hours'])) ?></td>
                            <td><?= e($assignment['group_name'] !== '' ? $assignment['group_name'] : '—') ?></td>
                            <?php if ($canEdit): ?>
                                <td class="text-end">
                                    <form method="post" action="<?= e(url('/assignments/' . (int) $assignment['id'] . '/delete')) ?>"
                                          data-confirm="<?= e(__('app.confirm_delete', ['name' => $assignment['subject_name'] . ' – ' . $assignment['teacher_name']])) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit"
                                                aria-label="<?= e(__('app.delete_named', ['name' => $assignment['subject_name'] . ' – ' . $assignment['teacher_name']])) ?>">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canEdit): ?>
                <div class="card-body border-top">
                    <h3 class="h6"><?= e(__('classes.add_assignment')) ?></h3>
                    <form class="row g-3" method="post" action="<?= e(url('/assignments')) ?>" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="class_id" value="<?= $id ?>">
                        <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'subject_id', 'label' => __('assignments.fields.subject_id'), 'type' => 'select', 'required' => true, 'options' => ['' => __('app.select_placeholder')] + $subjects]) ?></div>
                        <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'teacher_id', 'label' => __('assignments.fields.teacher_id'), 'type' => 'select', 'required' => true, 'options' => ['' => __('app.select_placeholder')] + $teachers]) ?></div>
                        <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'weekly_hours', 'label' => __('assignments.fields.weekly_hours'), 'attrs' => 'inputmode="decimal" dir="ltr" maxlength="4"']) ?></div>
                        <div class="col-6 col-md-5"><?= View::partial('partials/input', ['name' => 'group_name', 'label' => __('assignments.fields.group_name'), 'help' => __('assignments.group_help'), 'attrs' => 'maxlength="50"']) ?></div>
                        <div class="col-12 col-md-3 form-inline-action"><button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($students !== null): ?>
        <div class="col-12 col-xl-5">
            <div class="card">
                <div class="card-header d-flex justify-content-between">
                    <h2 class="h6 mb-0"><?= e(__('classes.students')) ?></h2>
                    <span class="text-secondary small"><?= e(__('app.total', ['count' => count($students)])) ?></span>
                </div>
                <?php if ($students === []): ?>
                    <div class="card-body text-secondary"><?= e(__('classes.no_students')) ?></div>
                <?php else: ?>
                    <ol class="list-group list-group-flush list-group-numbered">
                        <?php foreach ($students as $student): ?>
                            <li class="list-group-item">
                                <a href="<?= e(url('/students/' . (int) $student['id'])) ?>"><?= e($student['last_name'] . ' ' . $student['first_name']) ?></a>
                                <span class="text-secondary small" dir="ltr"><?= e($student['student_code']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
