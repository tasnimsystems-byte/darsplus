<?php
use App\Core\View;

View::share('title', __('risk.title'));
$badge = ['new' => 'text-bg-warning', 'monitoring' => 'text-bg-info', 'intervention' => 'text-bg-primary', 'dismissed' => 'text-bg-secondary'];
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('risk.title')) ?></h1>
    <?php if (can('risk.update')): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/risk/criteria')) ?>"><i class="bi bi-sliders" aria-hidden="true"></i> <?= e(__('risk.criteria')) ?></a>
    <?php endif; ?>
</div>

<div class="alert alert-info" role="note"><?= e(__('risk.principle')) ?></div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-8">
        <form class="row g-2 align-items-end" method="get" action="<?= e(url('/risk')) ?>">
            <div class="col-6 col-md-4">
                <label class="form-label" for="term_id"><?= e(__('assessments.fields.term_id')) ?></label>
                <select class="form-select" id="term_id" name="term_id">
                    <option value=""><?= e(__('app.all')) ?></option>
                    <?php foreach ($terms as $value => $label): ?>
                        <option value="<?= (int) $value ?>"<?= $filters['term_id'] === (int) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label" for="status"><?= e(__('risk.status')) ?></label>
                <select class="form-select" id="status" name="status">
                    <option value=""><?= e(__('app.all')) ?></option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= e($status) ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>><?= e(__('risk.statuses.' . $status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto"><button class="btn btn-outline-primary" type="submit"><?= e(__('app.filter')) ?></button></div>
        </form>
    </div>
    <?php if ($canRun): ?>
        <div class="col-12 col-lg-4">
            <form class="row g-2 align-items-end" method="post" action="<?= e(url('/risk/run')) ?>" data-confirm="<?= e(__('risk.confirm_run')) ?>">
                <?= csrf_field() ?>
                <div class="col">
                    <label class="form-label" for="run-term"><?= e(__('risk.run_for')) ?></label>
                    <select class="form-select" id="run-term" name="term_id">
                        <?php foreach ($terms as $value => $label): ?>
                            <option value="<?= (int) $value ?>"<?= $filters['term_id'] === (int) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto"><button class="btn btn-primary" type="submit"><?= e(__('risk.run')) ?></button></div>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden"><?= e(__('risk.title')) ?></caption>
            <thead><tr>
                <th scope="col"><?= e(__('assessments.student')) ?></th>
                <th scope="col"><?= e(__('students.fields.class_id')) ?></th>
                <th scope="col"><?= e(__('risk.criterion')) ?></th>
                <th scope="col"><?= e(__('assessments.fields.term_id')) ?></th>
                <th scope="col"><?= e(__('risk.status')) ?></th>
                <th scope="col" class="text-end"><?= e(__('app.actions')) ?></th>
            </tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-center text-secondary py-4"><?= e(__('risk.empty')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <th scope="row" class="fw-normal"><?= e($row['last_name'] . ' ' . $row['first_name']) ?></th>
                    <td><?= e($row['class_name']) ?></td>
                    <td><?= e($row['criterion_name']) ?></td>
                    <td><?= e($row['term_name']) ?></td>
                    <td><span class="badge <?= $badge[$row['review_status']] ?>"><?= e(__('risk.statuses.' . $row['review_status'])) ?></span></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/risk/' . (int) $row['id'])) ?>">
                            <?= e(__($row['review_status'] === 'new' ? 'risk.review' : 'risk.open')) ?>
                            <span class="visually-hidden">: <?= e($row['last_name'] . ' ' . $row['first_name'] . ' – ' . $row['criterion_name']) ?></span>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="text-secondary small mt-2"><?= e(__('app.total', ['count' => count($rows)])) ?></p>
