<?php
use App\Core\Lang;
use App\Core\View;

$isEdit = $user !== null;
View::share('title', __($isEdit ? 'users.edit' : 'users.add'));
$oldRoles = old('roles', null);
$checked = is_array($oldRoles) ? array_map('intval', $oldRoles) : $selected;
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__($isEdit ? 'users.edit' : 'users.add')) ?></h1>
</div>

<div class="card form-card">
    <div class="card-body">
        <form method="post" action="<?= e(url($isEdit ? '/users/' . (int) $user['id'] : '/users')) ?>" novalidate>
            <?= csrf_field() ?>
            <?php if ($isEdit): ?>
                <div class="mb-3">
                    <div class="form-label"><?= e(__('users.fields.username')) ?></div>
                    <div class="form-control-plaintext" dir="ltr"><?= e($user['username']) ?></div>
                </div>
            <?php else: ?>
                <?= View::partial('partials/input', [
                    'name' => 'username', 'label' => __('users.fields.username'), 'required' => true,
                    'help' => __('users.username_help'),
                    'attrs' => 'maxlength="60" autocapitalize="none" spellcheck="false" dir="ltr" autocomplete="off"',
                ]) ?>
            <?php endif; ?>

            <?= View::partial('partials/input', [
                'name' => 'full_name', 'label' => __('users.fields.full_name'), 'required' => true,
                'value' => $user['full_name'] ?? '', 'attrs' => 'maxlength="150"',
            ]) ?>
            <?= View::partial('partials/input', [
                'name' => 'email', 'label' => __('users.fields.email'), 'type' => 'email',
                'value' => $user['email'] ?? '', 'attrs' => 'maxlength="190" dir="ltr"',
            ]) ?>
            <?= View::partial('partials/input', [
                'name' => 'locale', 'label' => __('users.fields.locale'), 'type' => 'select',
                'value' => $user['locale'] ?? '', 'options' => ['' => __('users.locale_default')] + Lang::SUPPORTED,
            ]) ?>

            <fieldset class="mb-3"<?= $isSelf ? ' disabled' : '' ?>>
                <legend class="form-label fs-6">
                    <?= e(__('users.fields.roles')) ?> <span class="text-danger" aria-hidden="true">*</span>
                </legend>
                <?php if ($isSelf): ?>
                    <div class="form-text mb-2"><?= e(__('users.own_roles_locked')) ?></div>
                <?php endif; ?>
                <div class="row row-cols-1 row-cols-md-2 g-1">
                    <?php foreach ($roles as $role): ?>
                        <div class="col">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="roles[]" value="<?= (int) $role['id'] ?>"
                                       id="role-<?= (int) $role['id'] ?>"<?= in_array((int) $role['id'], $checked, true) ? ' checked' : '' ?>>
                                <label class="form-check-label" for="role-<?= (int) $role['id'] ?>">
                                    <?= e(role_label($role)) ?>
                                    <?php if ($role['level'] === 'network'): ?>
                                        <span class="badge text-bg-dark"><?= e(__('roles.network_level')) ?></span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?= field_error('roles') ?>
            </fieldset>

            <?php if ($isEdit && $canToggleActive): ?>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                           <?= $user['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_active"><?= e(__('users.fields.is_active')) ?></label>
                </div>
            <?php endif; ?>

            <?php if (!$isEdit): ?>
                <div class="alert alert-info" role="note"><?= e(__('users.create_password_note')) ?></div>
            <?php endif; ?>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit"><?= e(__('app.save')) ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url('/users')) ?>"><?= e(__('app.cancel')) ?></a>
            </div>
        </form>
    </div>
</div>
