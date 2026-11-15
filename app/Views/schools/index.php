<?php
use App\Core\Lang;
use App\Core\View;

View::share('title', __('schools.title'));
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('schools.title')) ?></h1>
    <?php if ($isNetwork && can('schools.create')): ?>
        <a class="btn btn-primary" href="<?= e(url('/schools/create')) ?>">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> <?= e(__('schools.add')) ?>
        </a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden"><?= e(__('schools.title')) ?></caption>
            <thead>
            <tr>
                <th scope="col"><?= e(__('schools.fields.name')) ?></th>
                <th scope="col"><?= e(__('schools.fields.code')) ?></th>
                <th scope="col"><?= e(__('schools.fields.default_locale')) ?></th>
                <th scope="col"><?= e(__('schools.fields.user_count')) ?></th>
                <th scope="col"><?= e(__('schools.fields.status')) ?></th>
                <?php if ($isNetwork && can('schools.update')): ?>
                    <th scope="col" class="text-end"><?= e(__('app.actions')) ?></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($schools as $school): ?>
                <tr>
                    <th scope="row" class="fw-normal"><?= e($school['name']) ?></th>
                    <td dir="ltr" class="text-end"><?= e($school['code']) ?></td>
                    <td><?= e(Lang::SUPPORTED[$school['default_locale']] ?? $school['default_locale']) ?></td>
                    <td><?= (int) $school['user_count'] ?></td>
                    <td>
                        <span class="badge <?= $school['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= e(__($school['is_active'] ? 'app.active' : 'app.inactive')) ?>
                        </span>
                    </td>
                    <?php if ($isNetwork && can('schools.update')): ?>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/schools/' . (int) $school['id'] . '/edit')) ?>"
                               aria-label="<?= e(__('schools.edit_named', ['name' => $school['name']])) ?>">
                                <i class="bi bi-pencil" aria-hidden="true"></i>
                            </a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
