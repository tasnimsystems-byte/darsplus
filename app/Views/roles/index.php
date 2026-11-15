<?php
use App\Core\View;

View::share('title', __('roles.title'));
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('roles.title')) ?></h1>
    <?php if (can('roles.create')): ?>
        <a class="btn btn-primary" href="<?= e(url('/roles/create')) ?>">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> <?= e(__('roles.add')) ?>
        </a>
    <?php endif; ?>
</div>
<p class="text-secondary"><?= e(__('roles.intro')) ?></p>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden"><?= e(__('roles.title')) ?></caption>
            <thead>
            <tr>
                <th scope="col"><?= e(__('roles.fields.name')) ?></th>
                <th scope="col"><?= e(__('roles.fields.type')) ?></th>
                <th scope="col"><?= e(__('roles.fields.user_count')) ?></th>
                <th scope="col" class="text-end"><?= e(__('app.actions')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($roles as $role): ?>
                <?php $id = (int) $role['id']; $editable = can('roles.update') && (!$role['is_system'] || $isNetwork); ?>
                <tr>
                    <th scope="row" class="fw-normal"><?= e(role_label($role)) ?></th>
                    <td>
                        <?php if ($role['level'] === 'network'): ?>
                            <span class="badge text-bg-dark"><?= e(__('roles.network_level')) ?></span>
                        <?php elseif ($role['is_system']): ?>
                            <span class="badge text-bg-light"><?= e(__('roles.system')) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-info"><?= e(__('roles.custom')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $role['user_count'] ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($editable): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url("/roles/$id/edit")) ?>"
                               aria-label="<?= e(__('roles.edit_named', ['name' => role_label($role)])) ?>">
                                <i class="bi bi-pencil" aria-hidden="true"></i>
                            </a>
                        <?php endif; ?>
                        <?php if (!$role['is_system'] && can('roles.delete')): ?>
                            <form class="d-inline" method="post" action="<?= e(url("/roles/$id/delete")) ?>"
                                  data-confirm="<?= e(__('roles.confirm_delete', ['name' => $role['name']])) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit"
                                        aria-label="<?= e(__('roles.delete_named', ['name' => $role['name']])) ?>">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
