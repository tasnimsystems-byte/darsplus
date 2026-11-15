<?php
use App\Controllers\AttendanceController;
use App\Core\View;

View::share('title', __('attendance.session_title'));
$id = (int) $session['id'];
$cancelled = $session['status'] === 'cancelled';
$choices = ['present', ...AttendanceController::STATUSES];
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($session['class_name']) ?> · <?= e($session['subject_name']) ?></h1>
        <p class="text-secondary mb-0">
            <span dir="ltr"><?= e(date('d/m/Y', strtotime($session['lesson_date']))) ?></span>
            · <?= e(__('attendance.period', ['n' => (int) $session['period_number']])) ?> · <?= e($session['teacher_name']) ?>
            <?php if ($cancelled): ?><span class="badge text-bg-secondary"><?= e(__('attendance.cancelled')) ?></span><?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url("/attendance/sessions/$id/cancel")) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline-secondary" type="submit"><?= e(__($cancelled ? 'attendance.restore' : 'attendance.cancel_lesson')) ?></button>
            </form>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/attendance') . '?date=' . $session['lesson_date']) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<?php if ($cancelled): ?>
    <div class="alert alert-secondary" role="note"><?= e(__('attendance.cancelled_note')) ?></div>
<?php elseif ($canEdit): ?>
    <p class="text-secondary"><?= e(__('attendance.mark_exceptions')) ?></p>
<?php endif; ?>

<form method="post" action="<?= e(url("/attendance/sessions/$id")) ?>">
    <?= csrf_field() ?>
    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden"><?= e(__('attendance.session_title')) ?></caption>
                <thead><tr>
                    <th scope="col"><?= e(__('assessments.student')) ?></th>
                    <th scope="col"><?= e(__('attendance.status')) ?></th>
                    <th scope="col"><?= e(__('attendance.note')) ?></th>
                </tr></thead>
                <tbody>
                <?php if ($students === []): ?>
                    <tr><td colspan="3" class="text-center text-secondary py-4"><?= e(__('classes.no_students')) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($students as $student): ?>
                    <?php $sid = (int) $student['id']; $name = $student['last_name'] . ' ' . $student['first_name']; $current = $student['status'] ?? 'present'; ?>
                    <tr>
                        <th scope="row" class="fw-normal" id="st-<?= $sid ?>"><?= e($name) ?></th>
                        <?php if ($canEdit && !$cancelled): ?>
                            <td>
                                <div class="btn-group btn-group-sm" role="radiogroup" aria-labelledby="st-<?= $sid ?>">
                                    <?php foreach ($choices as $choice): ?>
                                        <input class="btn-check" type="radio" name="students[<?= $sid ?>][status]" id="st-<?= $sid ?>-<?= e($choice) ?>" value="<?= e($choice) ?>"<?= $current === $choice ? ' checked' : '' ?>>
                                        <label class="btn btn-outline-<?= ['present' => 'success', 'absent' => 'danger', 'late' => 'warning', 'justified' => 'secondary'][$choice] ?>" for="st-<?= $sid ?>-<?= e($choice) ?>"><?= e(__('attendance.statuses.' . $choice)) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td><input class="form-control form-control-sm" name="students[<?= $sid ?>][note]" maxlength="255" value="<?= e($student['note'] ?? '') ?>" aria-label="<?= e(__('attendance.note') . ': ' . $name) ?>"></td>
                        <?php else: ?>
                            <td><?= e(__('attendance.statuses.' . $current)) ?></td>
                            <td><?= e($student['note'] ?? '') ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($canEdit && !$cancelled && $students !== []): ?>
        <button class="btn btn-primary" type="submit"><?= e(__('attendance.save')) ?></button>
    <?php endif; ?>
</form>
