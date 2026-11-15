<?php
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
                                <th scope="row"><a href="<?= e(url('/attendance/sessions/' . (int) $session['id'])) ?>"><?= e(__('attendance.period', ['n' => (int) $session['period_number']])) ?></a></th>
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
