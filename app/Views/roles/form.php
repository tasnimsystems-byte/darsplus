<?php
use App\Core\View;

$isEdit = $role !== null;
$heading = $isEdit ? __('roles.edit_named', ['name' => role_label($role)]) : __('roles.add');
View::share('title', $heading);
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e($heading) ?></h1>
</div>

<form method="post" action="<?= e(url($isEdit ? '/roles/' . (int) $role['id'] : '/roles')) ?>" novalidate>
    <?= csrf_field() ?>
    <?php if (!$isEdit || !$role['is_system']): ?>
        <div class="card form-card mb-3">
            <div class="card-body">
                <?= View::partial('partials/input', [
                    'name' => 'name', 'label' => __('roles.fields.name'), 'required' => true,
                    'value' => $role['name'] ?? '', 'attrs' => 'maxlength="100"',
                ]) ?>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning" role="note"><?= e(__('roles.system_warning')) ?></div>
    <?php endif; ?>

    <div class="card form-card mb-3">
        <div class="card-body">
            <?= View::partial('partials/input', [
                'name' => 'scope_mode', 'label' => __('roles.fields.scope_mode'), 'type' => 'select', 'required' => true,
                'value' => $role['scope_mode'] ?? 'assigned', 'help' => __('roles.scope_help'),
                'options' => array_filter([
                    'assigned' => __('roles.scope.assigned'),
                    'all' => $canGrantAll || ($role['scope_mode'] ?? '') === 'all' ? __('roles.scope.all') : null,
                ]),
            ]) ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0"><?= e(__('roles.permissions')) ?></h2></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 permission-matrix">
                <caption class="visually-hidden"><?= e(__('roles.permissions')) ?></caption>
                <thead>
                <tr>
                    <th scope="col"><?= e(__('roles.module')) ?></th>
                    <?php foreach ($actions as $action): ?>
                        <th scope="col" class="text-center"><?= e(__('permissions.actions.' . $action)) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($matrix as $module => $cells): ?>
                    <tr>
                        <th scope="row" class="fw-normal"><?= e(__('permissions.modules.' . $module)) ?></th>
                        <?php foreach ($actions as $action): ?>
                            <?php $cell = $cells[$action]; ?>
                            <td class="text-center">
                                <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= $cell['id'] ?>"
                                       aria-label="<?= e(__('permissions.modules.' . $module) . ' – ' . __('permissions.actions.' . $action)) ?>"
                                       <?= $cell['checked'] ? 'checked' : '' ?><?= $cell['grantable'] ? '' : ' disabled' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-secondary"><?= e(__('roles.grant_note')) ?></div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit"><?= e(__('app.save')) ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/roles')) ?>"><?= e(__('app.cancel')) ?></a>
    </div>
</form>
