<?php
use App\Core\View;

View::share('title', __('workplan.title'));
$badge = ['not_started' => 'text-bg-light', 'in_progress' => 'text-bg-primary', 'completed' => 'text-bg-success',
    'delayed' => 'text-bg-warning', 'needs_intervention' => 'text-bg-danger'];
$approved = ($plan['status'] ?? '') === 'approved';
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('workplan.title')) ?></h1>
        <p class="text-secondary mb-0">
            <?= e($year['name']) ?>
            <span class="badge <?= $approved ? 'text-bg-success' : 'text-bg-light' ?>"><?= e(__($approved ? 'workplan.status_approved' : 'workplan.status_draft')) ?></span>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($plan !== null && can('work_plan.approve') && !$year['is_locked']): ?>
            <form method="post" action="<?= e(url('/work-plan/approve')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline-primary" type="submit"><?= e(__($approved ? 'workplan.unapprove' : 'workplan.approve')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($objectives !== [] && can('work_plan.create') && !$year['is_locked']): ?>
            <a class="btn btn-primary" href="<?= e(url('/goals/create')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> <?= e(__('goals.add')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/goals')) ?>"><?= e(__('workplan.all_goals')) ?></a>
    </div>
</div>

<?php if ($summary['total'] > 0): ?>
    <div class="row g-3 mb-4">
        <?php foreach ([['total', 'bullseye'], ['completed', 'check2-circle'], ['delayed', 'hourglass-split'], ['needs_intervention', 'exclamation-triangle']] as [$key, $icon]): ?>
            <div class="col-6 col-xl-3">
                <div class="card kpi-card h-100"><div class="card-body">
                    <div class="kpi-icon"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></div>
                    <div><div class="kpi-value"><?= (int) $summary[$key] ?></div><div class="kpi-label"><?= e(__('workplan.summary.' . $key)) ?></div></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><h2 class="h6 mb-0"><?= e(__('workplan.vision')) ?></h2></div>
    <div class="card-body">
        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url('/work-plan')) ?>">
                <?= csrf_field() ?>
                <label class="visually-hidden" for="vision"><?= e(__('workplan.vision')) ?></label>
                <textarea class="form-control mb-3" id="vision" name="vision" rows="4" maxlength="5000"><?= e($plan['vision'] ?? '') ?></textarea>
                <button class="btn btn-primary" type="submit"><?= e(__('app.save')) ?></button>
            </form>
        <?php elseif (!empty($plan['vision'])): ?>
            <p class="mb-0"><?= nl2br(e($plan['vision'])) ?></p>
        <?php else: ?>
            <p class="text-secondary mb-0"><?= e(__('workplan.no_vision')) ?></p>
        <?php endif; ?>
    </div>
</div>

<h2 class="h5 mb-3"><?= e(__('workplan.objectives')) ?></h2>
<?php if ($objectives === []): ?>
    <div class="card card-body text-secondary mb-3"><?= e(__('workplan.no_objectives')) ?></div>
<?php endif; ?>
<?php foreach ($objectives as $objective): ?>
    <?php $oid = (int) $objective['id']; $list = $goals[$oid] ?? []; ?>
    <section class="card mb-3" aria-labelledby="objective-<?= $oid ?>">
        <div class="card-header d-flex justify-content-between align-items-start gap-2">
            <div>
                <h3 class="h6 mb-0" id="objective-<?= $oid ?>"><?= e($objective['title']) ?></h3>
                <?php if ($objective['description']): ?><div class="small text-secondary"><?= e($objective['description']) ?></div><?php endif; ?>
            </div>
            <?php if ($canEdit && $list === []): ?>
                <form method="post" action="<?= e(url("/work-plan/objectives/$oid/delete")) ?>" data-confirm="<?= e(__('app.confirm_delete', ['name' => $objective['title']])) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $objective['title']])) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($list === []): ?>
            <div class="card-body text-secondary"><?= e(__('workplan.no_goals')) ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden"><?= e($objective['title']) ?></caption>
                    <thead><tr>
                        <th scope="col"><?= e(__('goals.fields.title')) ?></th>
                        <th scope="col"><?= e(__('goals.fields.owner_user_id')) ?></th>
                        <th scope="col"><?= e(__('goals.fields.end_date')) ?></th>
                        <th scope="col"><?= e(__('goals.fields.status')) ?></th>
                        <th scope="col"><?= e(__('goals.fields.progress_percent')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($list as $goal): ?>
                        <tr>
                            <th scope="row" class="fw-normal">
                                <a href="<?= e(url('/goals/' . (int) $goal['id'])) ?>"><?= e($goal['title']) ?></a>
                                <?php if ($goal['domain_name']): ?><div class="small text-secondary"><?= e($goal['domain_name']) ?></div><?php endif; ?>
                            </th>
                            <td><?= e($goal['owner_name'] ?? '—') ?></td>
                            <td dir="ltr" class="text-end"><?= e(date('d/m/Y', strtotime($goal['end_date']))) ?></td>
                            <td><span class="badge <?= $badge[$goal['display_status']] ?>"><?= e(__('goals.statuses.' . $goal['display_status'])) ?></span></td>
                            <td class="text-nowrap">
                                <progress max="100" value="<?= (int) $goal['progress_percent'] ?>" aria-label="<?= e($goal['title']) ?>"></progress>
                                <?= (int) $goal['progress_percent'] ?>%
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<?php if ($canEdit): ?>
    <div class="card">
        <div class="card-header"><h2 class="h6 mb-0"><?= e(__('workplan.add_objective')) ?></h2></div>
        <div class="card-body">
            <form class="row g-3" method="post" action="<?= e(url('/work-plan/objectives')) ?>" novalidate>
                <?= csrf_field() ?>
                <div class="col-12 col-md-5"><?= View::partial('partials/input', ['name' => 'title', 'label' => __('workplan.objective_fields.title'), 'required' => true, 'attrs' => 'maxlength="190"']) ?></div>
                <div class="col-12 col-md-5"><?= View::partial('partials/input', ['name' => 'description', 'label' => __('workplan.objective_fields.description'), 'attrs' => 'maxlength="2000"']) ?></div>
                <div class="col-12 col-md-2 form-inline-action"><button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button></div>
            </form>
        </div>
    </div>
<?php endif; ?>
