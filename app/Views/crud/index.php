<?php
use App\Core\View;

/** @var array $def @var array $fields @var array $rows */
$lang = $def['lang'];
$module = $def['module'];
View::share('title', __("$lang.title"));

$columns = array_filter($fields, static fn ($f) => $f['list']);
$canUpdate = !$readOnly && can("$module.update");
$canDelete = !$readOnly && can("$module.delete");
$rowName = $def['rowName'] ?? static fn (array $row): string => (string) $row[$def['nameField']];
$actionCount = (int) $def['show'] + (int) $canUpdate + (int) $canDelete + count($def['rowActions']);

$display = static function (array $field, mixed $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    return match ($field['type']) {
        'select' => (string) ($field['options'][$value] ?? '—'),
        'checkbox' => __($value ? 'app.yes' : 'app.no'),
        'date' => date('d/m/Y', strtotime((string) $value)),
        default => (string) $value,
    };
};
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__("$lang.title")) ?></h1>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($def['headerActions'] as $action): ?>
            <?php if (!$readOnly && can($action['permission'])): ?>
                <a class="btn btn-outline-primary" href="<?= e(url($action['path'])) ?>">
                    <i class="bi bi-<?= e($action['icon']) ?>" aria-hidden="true"></i> <?= e(__($action['label'])) ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$readOnly && can("$module.create")): ?>
            <a class="btn btn-primary" href="<?= e(url($def['path'] . '/create')) ?>">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> <?= e(__("$lang.add")) ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($readOnly): ?>
    <div class="alert alert-secondary" role="note"><i class="bi bi-lock" aria-hidden="true"></i> <?= e(__('app.errors.year_locked')) ?></div>
<?php endif; ?>

<?php if ($def['search'] !== [] || $def['filters'] !== []): ?>
    <form class="row g-2 mb-3 align-items-end" method="get" action="<?= e(url($def['path'])) ?>" role="search">
        <?php if ($def['search'] !== []): ?>
            <div class="col-12 col-md-4">
                <label class="form-label" for="q"><?= e(__('app.search')) ?></label>
                <input class="form-control" type="search" id="q" name="q" maxlength="100" value="<?= e($search) ?>">
            </div>
        <?php endif; ?>
        <?php foreach ($def['filters'] as $name => $filter): ?>
            <div class="col-6 col-md-2">
                <label class="form-label" for="filter-<?= e($name) ?>"><?= e(__($filter['label'])) ?></label>
                <select class="form-select" id="filter-<?= e($name) ?>" name="<?= e($name) ?>">
                    <option value=""><?= e(__('app.all')) ?></option>
                    <?php foreach ($filter['options'] as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= ($activeFilters[$name] ?? '') === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>
        <div class="col-auto">
            <button class="btn btn-outline-primary" type="submit"><?= e(__('app.filter')) ?></button>
            <?php if ($search !== '' || $activeFilters !== []): ?>
                <a class="btn btn-link" href="<?= e(url($def['path'])) ?>"><?= e(__('app.clear')) ?></a>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden"><?= e(__("$lang.title")) ?></caption>
            <thead>
            <tr>
                <?php foreach ($columns as $name => $field): ?>
                    <th scope="col"><?= e(__("$lang.fields.$name")) ?></th>
                <?php endforeach; ?>
                <?php foreach ($def['listExtra'] as $label): ?>
                    <th scope="col"><?= e(__($label)) ?></th>
                <?php endforeach; ?>
                <?php if ($actionCount > 0): ?><th scope="col" class="text-end"><?= e(__('app.actions')) ?></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= count($columns) + count($def['listExtra']) + 1 ?>" class="text-center text-secondary py-4"><?= e(__('app.none')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php $id = (int) $row['id']; $name = $rowName($row); $first = true; ?>
                <tr>
                    <?php foreach ($columns as $column => $field): ?>
                        <?php if ($first): $first = false; ?>
                            <th scope="row" class="fw-normal">
                                <?php if ($def['show']): ?>
                                    <a href="<?= e(url("{$def['path']}/$id")) ?>"><?= e($display($field, $row[$column])) ?></a>
                                <?php else: ?>
                                    <?= e($display($field, $row[$column])) ?>
                                <?php endif; ?>
                            </th>
                        <?php else: ?>
                            <td><?= e($display($field, $row[$column])) ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php foreach (array_keys($def['listExtra']) as $column): ?>
                        <td><?= e($row[$column] ?? '—') ?></td>
                    <?php endforeach; ?>
                    <?php if ($actionCount > 0): ?>
                        <td class="text-end text-nowrap">
                            <?php foreach ($def['rowActions'] as $action): ?>
                                <?php if (can($action['permission']) && ($action['when'])($row)): ?>
                                    <form class="d-inline" method="post" action="<?= e(url(str_replace('{id}', (string) $id, $action['path']))) ?>"
                                          <?= isset($action['confirm']) ? 'data-confirm="' . e(__($action['confirm'], ['name' => $name])) . '"' : '' ?>>
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">
                                            <i class="bi bi-<?= e($action['icon']) ?>" aria-hidden="true"></i>
                                            <span class="d-none d-md-inline"><?= e(__($action['label'])) ?></span>
                                            <span class="visually-hidden d-md-none"><?= e(__($action['label'])) ?></span>
                                            <span class="visually-hidden">: <?= e($name) ?></span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($canUpdate): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(url("{$def['path']}/$id/edit")) ?>"
                                   aria-label="<?= e(__('app.edit_named', ['name' => $name])) ?>">
                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form class="d-inline" method="post" action="<?= e(url("{$def['path']}/$id/delete")) ?>"
                                      data-confirm="<?= e(__('app.confirm_delete', ['name' => $name])) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-danger" type="submit"
                                            aria-label="<?= e(__('app.delete_named', ['name' => $name])) ?>">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= View::partial('partials/pagination', [
    'total' => $total, 'page' => $page, 'perPage' => $perPage, 'path' => $def['path'],
    'query' => ['q' => $search] + $activeFilters,
]) ?>
