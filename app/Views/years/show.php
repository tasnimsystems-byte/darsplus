<?php
use App\Core\View;

View::share('title', $year['name']);
$id = (int) $year['id'];
$canEdit = can('structure.update') && !$year['is_locked'];
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('years.year_named', ['name' => $year['name']])) ?></h1>
        <p class="text-secondary mb-0">
            <span dir="ltr"><?= e(date('d/m/Y', strtotime($year['start_date']))) ?> – <?= e(date('d/m/Y', strtotime($year['end_date']))) ?></span>
            <?php if ($year['is_current']): ?><span class="badge text-bg-success"><?= e(__('years.fields.is_current')) ?></span><?php endif; ?>
            <?php if ($year['is_locked']): ?><span class="badge text-bg-secondary"><i class="bi bi-lock" aria-hidden="true"></i> <?= e(__('years.fields.is_locked')) ?></span><?php endif; ?>
            · <?= e(__('years.class_count', ['count' => $classCount])) ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (can('structure.create')): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/years/$id/transition")) ?>">
                <i class="bi bi-arrow-up-right-circle" aria-hidden="true"></i> <?= e(__('years.transition.open')) ?>
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/years')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h2 class="h6 mb-0"><?= e(__('years.terms')) ?></h2></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <caption class="visually-hidden"><?= e(__('years.terms')) ?></caption>
            <thead>
            <tr>
                <th scope="col"><?= e(__('years.term_fields.name')) ?></th>
                <th scope="col"><?= e(__('years.term_fields.start_date')) ?></th>
                <th scope="col"><?= e(__('years.term_fields.end_date')) ?></th>
                <?php if ($canEdit): ?><th scope="col" class="text-end"><?= e(__('app.actions')) ?></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($terms === []): ?>
                <tr><td colspan="4" class="text-center text-secondary py-4"><?= e(__('years.no_terms')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($terms as $term): ?>
                <tr>
                    <th scope="row" class="fw-normal"><?= e($term['name']) ?></th>
                    <td dir="ltr" class="text-end"><?= e(date('d/m/Y', strtotime($term['start_date']))) ?></td>
                    <td dir="ltr" class="text-end"><?= e(date('d/m/Y', strtotime($term['end_date']))) ?></td>
                    <?php if ($canEdit): ?>
                        <td class="text-end">
                            <form method="post" action="<?= e(url("/years/$id/terms/" . (int) $term['id'] . '/delete')) ?>"
                                  data-confirm="<?= e(__('app.confirm_delete', ['name' => $term['name']])) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $term['name']])) ?>">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($canEdit): ?>
        <div class="card-body border-top">
            <h3 class="h6"><?= e(__('years.add_term')) ?></h3>
            <form class="row g-3 align-items-start" method="post" action="<?= e(url("/years/$id/terms")) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'name', 'label' => __('years.term_fields.name'), 'required' => true, 'attrs' => 'maxlength="60"']) ?></div>
                <div class="col-6 col-md-3"><?= View::partial('partials/input', ['name' => 'start_date', 'label' => __('years.term_fields.start_date'), 'type' => 'date', 'required' => true]) ?></div>
                <div class="col-6 col-md-3"><?= View::partial('partials/input', ['name' => 'end_date', 'label' => __('years.term_fields.end_date'), 'type' => 'date', 'required' => true]) ?></div>
                <div class="col-12 col-md-2 form-inline-action"><button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button></div>
            </form>
        </div>
    <?php endif; ?>
</div>
