<?php
use App\Core\View;

View::share('title', __('students.import.title'));
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('students.import.title')) ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/students')) ?>"><?= e(__('app.back')) ?></a>
</div>

<?php if ($analysis === null): ?>
    <div class="card form-card">
        <div class="card-body">
            <p><?= e(__('students.import.intro')) ?></p>
            <ul class="small text-secondary">
                <li><?= e(__('students.import.columns')) ?></li>
                <li><?= e(__('students.import.rule_codes')) ?></li>
                <li><?= e(__('students.import.rule_class', ['year' => $year['name'] ?? '—'])) ?></li>
                <li><?= e(__('students.import.rule_privacy')) ?></li>
            </ul>
            <form method="post" action="<?= e(url('/students/import')) ?>" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <?= View::partial('partials/input', [
                    'name' => 'file', 'label' => __('students.import.file'), 'type' => 'file', 'required' => true,
                    'help' => __('students.import.file_help'), 'attrs' => 'accept=".csv,.xlsx"',
                ]) ?>
                <button class="btn btn-primary" type="submit"><?= e(__('students.import.check')) ?></button>
            </form>
        </div>
    </div>
<?php else: ?>
    <?php $counts = $analysis['counts']; ?>
    <div class="row g-3 mb-3">
        <?php foreach (['ok' => 'success', 'exists' => 'secondary', 'error' => 'danger'] as $status => $colour): ?>
            <div class="col-12 col-md-4">
                <div class="card border-<?= $colour ?>">
                    <div class="card-body">
                        <div class="kpi-value"><?= (int) $counts[$status] ?></div>
                        <div class="kpi-label"><?= e(__('students.import.status.' . $status)) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="alert alert-info" role="status"><?= e(__('students.import.preview_note')) ?></div>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden"><?= e(__('students.import.title')) ?></caption>
                <thead><tr>
                    <th scope="col"><?= e(__('students.import.line')) ?></th>
                    <th scope="col"><?= e(__('students.fields.last_name')) ?></th>
                    <th scope="col"><?= e(__('students.fields.first_name')) ?></th>
                    <th scope="col"><?= e(__('students.fields.student_code')) ?></th>
                    <th scope="col"><?= e(__('students.fields.class_id')) ?></th>
                    <th scope="col"><?= e(__('students.import.result')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($analysis['rows'] as $row): ?>
                    <tr class="<?= $row['status'] === 'error' ? 'table-danger' : '' ?>">
                        <th scope="row" class="fw-normal"><?= (int) $row['line'] ?></th>
                        <td><?= e($row['data']['last_name']) ?></td>
                        <td><?= e($row['data']['first_name']) ?></td>
                        <td dir="ltr" class="text-end"><?= e($row['data']['student_code'] !== '' ? $row['data']['student_code'] : __('students.import.auto_code')) ?></td>
                        <td><?= e($row['data']['class'] !== '' ? $row['data']['class'] : '—') ?></td>
                        <td>
                            <?php if ($row['status'] === 'error'): ?>
                                <?= e(implode(' ', array_map('__', $row['errors']))) ?>
                            <?php else: ?>
                                <?= e(__('students.import.status.' . $row['status'])) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <?php if ($counts['ok'] > 0): ?>
            <form method="post" action="<?= e(url('/students/import/confirm')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit"><?= e(__('students.import.confirm', ['count' => $counts['ok']])) ?></button>
            </form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/students/import/cancel')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-outline-secondary" type="submit"><?= e(__('app.cancel')) ?></button>
        </form>
    </div>
<?php endif; ?>
