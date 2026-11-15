<?php
use App\Core\App;
use App\Core\View;

View::share('title', __('users.title'));
$selfId = App::auth()->id();
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('users.title')) ?></h1>
    <?php if (can('users.create')): ?>
        <a class="btn btn-primary" href="<?= e(url('/users/create')) ?>">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> <?= e(__('users.add')) ?>
        </a>
    <?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get" action="<?= e(url('/users')) ?>" role="search">
    <div class="col-12 col-sm-8 col-md-5">
        <label class="visually-hidden" for="q"><?= e(__('users.search_placeholder')) ?></label>
        <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= e($search) ?>"
               placeholder="<?= e(__('users.search_placeholder')) ?>">
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-primary" type="submit"><?= e(__('app.search')) ?></button>
        <?php if ($search !== ''): ?>
            <a class="btn btn-link" href="<?= e(url('/users')) ?>"><?= e(__('app.clear')) ?></a>
        <?php endif; ?>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden"><?= e(__('users.title')) ?></caption>
            <thead>
            <tr>
                <th scope="col"><?= e(__('users.fields.full_name')) ?></th>
                <th scope="col"><?= e(__('users.fields.username')) ?></th>
                <th scope="col"><?= e(__('users.fields.roles')) ?></th>
                <th scope="col"><?= e(__('users.fields.status')) ?></th>
                <th scope="col"><?= e(__('users.fields.last_login_at')) ?></th>
                <th scope="col" class="text-end"><?= e(__('app.actions')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($users === []): ?>
                <tr><td colspan="6" class="text-center text-secondary py-4"><?= e(__('app.none')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($users as $user): ?>
                <?php $id = (int) $user['id']; ?>
                <tr>
                    <th scope="row" class="fw-normal">
                        <?= e($user['full_name']) ?>
                        <?php if ($user['email']): ?><div class="small text-secondary" dir="ltr"><?= e($user['email']) ?></div><?php endif; ?>
                    </th>
                    <td dir="ltr" class="text-end"><?= e($user['username']) ?></td>
                    <td>
                        <?php foreach ($roles[$id] ?? [] as $role): ?>
                            <span class="badge <?= $role['level'] === 'network' ? 'text-bg-dark' : 'text-bg-light' ?>"><?= e(role_label($role)) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <?php if ($user['is_active']): ?>
                            <span class="badge text-bg-success"><?= e(__('app.active')) ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary"><?= e(__('app.inactive')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><time dir="ltr"><?= e(format_datetime($user['last_login_at'])) ?></time></td>
                    <td class="text-end text-nowrap">
                        <?php if (can('users.update')): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url("/users/$id/edit")) ?>"
                               aria-label="<?= e(__('users.edit_named', ['name' => $user['full_name']])) ?>">
                                <i class="bi bi-pencil" aria-hidden="true"></i>
                            </a>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url("/users/$id/scopes")) ?>"
                               aria-label="<?= e(__('scopes.manage_named', ['name' => $user['full_name']])) ?>">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </a>
                            <?php if ($id !== $selfId): ?>
                                <form class="d-inline" method="post" action="<?= e(url("/users/$id/reset-password")) ?>"
                                      data-confirm="<?= e(__('users.confirm_reset', ['name' => $user['full_name']])) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"
                                            aria-label="<?= e(__('users.reset_named', ['name' => $user['full_name']])) ?>">
                                        <i class="bi bi-key" aria-hidden="true"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (can('users.delete') && $id !== $selfId): ?>
                            <form class="d-inline" method="post" action="<?= e(url("/users/$id/delete")) ?>"
                                  data-confirm="<?= e(__('users.confirm_delete', ['name' => $user['full_name']])) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit"
                                        aria-label="<?= e(__('users.delete_named', ['name' => $user['full_name']])) ?>">
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
<?= View::partial('partials/pagination', [
    'total' => $total, 'page' => $page, 'perPage' => $perPage, 'path' => '/users', 'query' => ['q' => $search],
]) ?>
