<?php
use App\Controllers\TimetableController;
use App\Core\View;

View::share('title', __('attendance.title'));
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('attendance.title')) ?></h1>
        <p class="text-secondary mb-0"><span dir="ltr"><?= e(date('d/m/Y', strtotime($date))) ?></span></p>
    </div>
    <a class="btn btn-outline-primary" href="<?= e(url('/attendance/report')) ?>"><i class="bi bi-table" aria-hidden="true"></i> <?= e(__('attendance.report')) ?></a>
</div>

<form class="row g-2 align-items-end mb-4" method="get" action="<?= e(url('/attendance')) ?>">
    <div class="col-auto">
        <label class="form-label" for="date"><?= e(__('attendance.fields.lesson_date')) ?></label>
        <input class="form-control" type="date" id="date" name="date" value="<?= e($date) ?>" min="<?= e($year['start_date']) ?>" max="<?= e($year['end_date']) ?>">
    </div>
    <div class="col-auto"><button class="btn btn-outline-primary" type="submit"><?= e(__('attendance.show_day')) ?></button></div>
</form>

<?php if ($canRecord && $hasTimetable): ?>
    <div class="card mb-4">
        <div class="card-header"><h2 class="h6 mb-0"><?= e(__('timetable.today')) ?></h2></div>
        <?php if ($scheduled === []): ?>
            <div class="card-body text-secondary"><?= e(__('timetable.today_none')) ?></div>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($scheduled as $lesson): ?>
                    <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <span>
                            <strong><?= e(TimetableController::periodLabel($periods, (int) $lesson['period_number'])) ?></strong>
                            | <?= e($assignments[(int) $lesson['teaching_assignment_id']] ?? '') ?>
                        </span>
                        <?php if ($lesson['session_id'] !== null): ?>
                            <a class="btn btn-sm btn-outline-success" href="<?= e(url('/attendance/sessions/' . (int) $lesson['session_id'])) ?>"><i class="bi bi-check2" aria-hidden="true"></i> <?= e(__('timetable.opened')) ?></a>
                        <?php elseif ($date > date('Y-m-d')): ?>
                            <span class="badge text-bg-light"><?= e(__('timetable.future')) ?></span>
                        <?php else: ?>
                            <form method="post" action="<?= e(url('/attendance/sessions')) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="teaching_assignment_id" value="<?= (int) $lesson['teaching_assignment_id'] ?>">
                                <input type="hidden" name="lesson_date" value="<?= e($date) ?>">
                                <input type="hidden" name="period_number" value="<?= (int) $lesson['period_number'] ?>">
                                <button class="btn btn-sm btn-primary" type="submit"><?= e(__('timetable.open')) ?></button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <?php if ($canRecord && $assignments !== []): ?>
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('attendance.open_lesson')) ?></h2></div>
                <form class="card-body" method="post" action="<?= e(url('/attendance/sessions')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <?= View::partial('partials/input', ['name' => 'teaching_assignment_id', 'label' => __('attendance.fields.teaching_assignment_id'), 'type' => 'select', 'required' => true,
                        'options' => (count($assignments) > 1 ? ['' => __('app.select_placeholder')] : []) + $assignments]) ?>
                    <div class="row g-2">
                        <div class="col-7"><?= View::partial('partials/input', ['name' => 'lesson_date', 'label' => __('attendance.fields.lesson_date'), 'type' => 'date', 'required' => true, 'value' => $date]) ?></div>
                        <div class="col-5"><?= View::partial('partials/input', ['name' => 'period_number', 'label' => __('attendance.fields.period_number'), 'type' => 'number', 'required' => true, 'value' => 1, 'attrs' => 'min="1" max="14"']) ?></div>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= e(__('attendance.open_button')) ?></button>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <div class="col-12 <?= $canRecord && $assignments !== [] ? 'col-lg-7' : '' ?>">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('attendance.lessons_on_day')) ?></h2></div>
            <?php if ($sessions === []): ?>
                <div class="card-body text-secondary"><?= e(__('attendance.no_lessons')) ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <caption class="visually-hidden"><?= e(__('attendance.lessons_on_day')) ?></caption>
                        <thead><tr>
                            <th scope="col"><?= e(__('attendance.fields.period_number')) ?></th>
                            <th scope="col"><?= e(__('assignments.fields.class_id')) ?></th>
                            <th scope="col"><?= e(__('assignments.fields.subject_id')) ?></th>
                            <th scope="col"><?= e(__('assignments.fields.teacher_id')) ?></th>
                            <th scope="col"><?= e(__('attendance.missing')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($sessions as $session): ?>
                            <tr>
                                <th scope="row"><a href="<?= e(url('/attendance/sessions/' . (int) $session['id'])) ?>"><?= e(TimetableController::periodLabel($periods, (int) $session['period_number'])) ?></a></th>
                                <td><?= e($session['class_name']) ?></td>
                                <td><?= e($session['subject_name']) ?></td>
                                <td><?= e($session['teacher_name']) ?></td>
                                <td>
                                    <?php if ($session['status'] === 'cancelled'): ?>
                                        <span class="badge text-bg-secondary"><?= e(__('attendance.cancelled')) ?></span>
                                    <?php else: ?>
                                        <?= (int) $session['missing'] ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
