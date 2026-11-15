<?php
use App\Core\View;

View::share('title', __('audit.title'));
$actionOptions = ['' => __('audit.all')];
foreach ($actions as $action) {
    $actionOptions[$action] = trans_or('audit.actions.' . $action, $action);
}
$entityOptions = ['' => __('audit.all')];
foreach ($entities as $entity) {
    $entityOptions[$entity] = trans_or('audit.entities.' . $entity, $entity);
}
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('audit.title')) ?></h1>
</div>

<form class="card card-body mb-3" method="get" action="<?= e(url('/audit')) ?>">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label" for="f-action"><?= e(__('audit.fields.action')) ?></label>
            <select class="form-select" id="f-action" name="action">
                <?php foreach ($actionOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $filters['action'] === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="f-entity"><?= e(__('audit.fields.entity')) ?></label>
            <select class="form-select" id="f-entity" name="entity">
                <?php foreach ($entityOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $filters['entity'] === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" for="f-from"><?= e(__('audit.fields.from')) ?></label>
            <input class="form-control" type="date" id="f-from" name="from" value="<?= e($filters['from']) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label" for="f-to"><?= e(__('audit.fields.to')) ?></label>
            <input class="form-control" type="date" id="f-to" name="to" value="<?= e($filters['to']) ?>">
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-outline-primary" type="submit"><?= e(__('app.filter')) ?></button>
            <a class="btn btn-link" href="<?= e(url('/audit')) ?>"><?= e(__('app.clear')) ?></a>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <caption class="visually-hidden"><?= e(__('audit.title')) ?></caption>
            <thead>
            <tr>
                <th scope="col"><?= e(__('audit.fields.time')) ?></th>
                <th scope="col"><?= e(__('audit.fields.user')) ?></th>
                <th scope="col"><?= e(__('audit.fields.action')) ?></th>
                <th scope="col"><?= e(__('audit.fields.entity')) ?></th>
                <th scope="col"><?= e(__('audit.fields.ip')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="5" class="text-center text-secondary py-4"><?= e(__('app.none')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><time dir="ltr"><?= e(format_datetime($row['created_at'])) ?></time></td>
                    <td><?= e($row['full_name'] ?? __('audit.system')) ?></td>
                    <td><?= e(trans_or('audit.actions.' . $row['action'], $row['action'])) ?></td>
                    <td>
                        <?= e(trans_or('audit.entities.' . $row['entity_type'], $row['entity_type'])) ?>
                        <?php if ($row['entity_id'] !== null): ?><span class="text-secondary" dir="ltr">#<?= (int) $row['entity_id'] ?></span><?php endif; ?>
                    </td>
                    <td dir="ltr" class="text-end"><?= e($row['ip_address'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= View::partial('partials/pagination', [
    'total' => $total, 'page' => $page, 'perPage' => $perPage, 'path' => '/audit', 'query' => $filters,
]) ?>
