<?php
use App\Core\View;

View::share('title', __('attendance.report'));
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('attendance.report')) ?></h1>
        <p class="text-secondary mb-0">
            <?= e(__('attendance.overall_rate')) ?>:
            <strong><?= $rate === null ? '—' : e(format_hours($rate)) . '%' ?></strong>
        </p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/attendance')) ?>"><?= e(__('app.back')) ?></a>
</div>

<?php if ($partial): ?>
    <div class="alert alert-secondary" role="note"><?= e(__('attendance.partial_note')) ?></div>
<?php endif; ?>

<form class="row g-2 align-items-end mb-3" method="get" action="<?= e(url('/attendance/report')) ?>">
    <div class="col-6 col-md-3">
        <label class="form-label" for="class_id"><?= e(__('students.fields.class_id')) ?></label>
        <select class="form-select" id="class_id" name="class_id">
            <?php foreach ($classes as $value => $label): ?>
                <option value="<?= (int) $value ?>"<?= $classId === (int) $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label" for="term_id"><?= e(__('assessments.fields.term_id')) ?></label>
        <select class="form-select" id="term_id" name="term_id">
            <option value=""><?= e(__('attendance.whole_year')) ?></option>
            <?php foreach ($terms as $value => $label): ?>
                <option value="<?= (int) $value ?>"<?= $termId === (int) $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-primary" type="submit"><?= e(__('app.filter')) ?></button></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <caption class="visually-hidden"><?= e(__('attendance.report')) ?></caption>
            <thead><tr>
                <th scope="col"><?= e(__('assessments.student')) ?></th>
                <th scope="col"><?= e(__('attendance.lessons')) ?></th>
                <th scope="col"><?= e(__('attendance.statuses.absent')) ?></th>
                <th scope="col"><?= e(__('attendance.statuses.justified')) ?></th>
                <th scope="col"><?= e(__('attendance.statuses.late')) ?></th>
                <th scope="col"><?= e(__('attendance.rate')) ?></th>
            </tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-center text-secondary py-4"><?= e(__('app.none')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <th scope="row" class="fw-normal"><?= e($row['last_name'] . ' ' . $row['first_name']) ?></th>
                    <td><?= (int) $row['sessions'] ?></td>
                    <td><?= (int) $row['absent'] ?></td>
                    <td><?= (int) $row['justified'] ?></td>
                    <td><?= (int) $row['late'] ?></td>
                    <td class="text-nowrap">
                        <?php if ($row['rate'] === null): ?>—
                        <?php else: ?>
                            <progress max="100" value="<?= e($row['rate']) ?>" aria-label="<?= e($row['last_name'] . ' ' . $row['first_name']) ?>"></progress>
                            <?= e(format_hours($row['rate'])) ?>%
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-secondary"><?= e(__('attendance.method')) ?></div>
</div>
