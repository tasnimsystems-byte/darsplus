<?php
use App\Core\View;

View::share('title', __('scopes.title'));
$id = (int) $user['id'];
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('scopes.title_named', ['name' => $user['full_name']])) ?></h1>
        <p class="text-secondary mb-0"><?= e(__('scopes.year', ['year' => $year['name'] ?? __('nav.no_year')])) ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/users')) ?>"><?= e(__('app.back')) ?></a>
</div>

<div class="alert alert-info" role="note"><?= e(__('scopes.intro')) ?></div>

<div class="card mb-4">
    <div class="card-header"><h2 class="h6 mb-0"><?= e(__('scopes.current')) ?></h2></div>
    <?php if ($scopes === []): ?>
        <div class="card-body text-secondary"><?= e(__('scopes.none')) ?></div>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($scopes as $scope): ?>
                <?php $label = __('scopes.types.' . $scope['scope_type']) . ($scope['target_name'] !== null ? ': ' . $scope['target_name'] : ''); ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><?= e($label) ?></span>
                    <form method="post" action="<?= e(url("/users/$id/scopes/" . (int) $scope['id'] . '/delete')) ?>"
                          data-confirm="<?= e(__('app.confirm_delete', ['name' => $label])) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $label])) ?>">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php if ($year !== null): ?>
    <div class="row g-3">
        <?php foreach ($targets as $type => $options): ?>
            <div class="col-12 col-md-4">
                <form class="card h-100" method="post" action="<?= e(url("/users/$id/scopes")) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="scope_type" value="<?= e($type) ?>">
                    <div class="card-body">
                        <label class="form-label" for="scope-<?= e($type) ?>"><?= e(__('scopes.add.' . $type)) ?></label>
                        <select class="form-select mb-3" id="scope-<?= e($type) ?>" name="scope_id" required>
                            <?php foreach ($options as $value => $label): ?>
                                <option value="<?= (int) $value ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-primary" type="submit"<?= $options === [] ? ' disabled' : '' ?>><?= e(__('app.add')) ?></button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <form class="mt-3" method="post" action="<?= e(url("/users/$id/scopes")) ?>"
          data-confirm="<?= e(__('scopes.confirm_all', ['name' => $user['full_name']])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="scope_type" value="all">
        <button class="btn btn-outline-secondary" type="submit"><?= e(__('scopes.add.all')) ?></button>
    </form>
<?php endif; ?>
