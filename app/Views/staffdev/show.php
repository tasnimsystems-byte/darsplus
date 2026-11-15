<?php
use App\Controllers\StaffDevController;
use App\Core\View;

$name = $teacher['last_name'] . ' ' . $teacher['first_name'];
View::share('title', __('staffdev.title'));
$id = (int) $teacher['id'];
$date = static fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
$trainingStatuses = [];
foreach (StaffDevController::TRAINING_STATUSES as $status) {
    $trainingStatuses[$status] = __('staffdev.training_statuses.' . $status);
}
$hours = array_sum(array_map(static fn ($t) => $t['status'] === 'completed' ? (int) $t['hours'] : 0, $trainings));
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($own ? __('staffdev.mine') : __('staffdev.title_named', ['name' => $name])) ?></h1>
        <p class="text-secondary mb-0"><?= e($year['name'] ?? __('nav.no_year')) ?></p>
    </div>
    <?php if (!$own): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url("/teachers/$id")) ?>"><?= e(__('app.back')) ?></a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('staffdev.goals')) ?></h2></div>
            <?php if ($goals === []): ?>
                <div class="card-body text-secondary"><?= e(__('staffdev.no_goals')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($goals as $goal): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div><?= e($goal['title']) ?> <span class="badge <?= ['open' => 'text-bg-primary', 'achieved' => 'text-bg-success', 'dropped' => 'text-bg-secondary'][$goal['status']] ?>"><?= e(__('staffdev.goal_statuses.' . $goal['status'])) ?></span></div>
                                <div class="small text-secondary">
                                    <?= $goal['success_measure'] ? e($goal['success_measure']) . ' · ' : '' ?><span dir="ltr"><?= e($date($goal['due_date'])) ?></span>
                                </div>
                            </div>
                            <?php if ($canEdit): ?>
                                <div class="d-flex gap-1">
                                    <form method="post" action="<?= e(url("/staff-development/$id/goals/" . (int) $goal['id'])) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="status" value="<?= $goal['status'] === 'achieved' ? 'open' : 'achieved' ?>">
                                        <button class="btn btn-sm <?= $goal['status'] === 'achieved' ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit"
                                                aria-label="<?= e(__($goal['status'] === 'achieved' ? 'goals.reopen_action' : 'staffdev.mark_achieved') . ': ' . $goal['title']) ?>">
                                            <i class="bi bi-<?= $goal['status'] === 'achieved' ? 'arrow-counterclockwise' : 'check2' ?>" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                    <form method="post" action="<?= e(url("/staff-development/$id/goals/" . (int) $goal['id'] . '/delete')) ?>" data-confirm="<?= e(__('app.confirm_delete', ['name' => $goal['title']])) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $goal['title']])) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <form class="card-body border-top row g-2" method="post" action="<?= e(url("/staff-development/$id/goals")) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-12"><?= View::partial('partials/input', ['name' => 'title', 'label' => __('staffdev.goal_fields.title'), 'required' => true, 'attrs' => 'maxlength="190"']) ?></div>
                    <div class="col-12 col-md-8"><?= View::partial('partials/input', ['name' => 'success_measure', 'label' => __('staffdev.goal_fields.success_measure'), 'attrs' => 'maxlength="2000"']) ?></div>
                    <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'due_date', 'label' => __('staffdev.goal_fields.due_date'), 'type' => 'date']) ?></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between">
                <h2 class="h6 mb-0"><?= e(__('staffdev.trainings')) ?></h2>
                <?php if ($hours > 0): ?><span class="small text-secondary"><?= e(__('staffdev.hours_completed', ['hours' => $hours])) ?></span><?php endif; ?>
            </div>
            <?php if ($trainings === []): ?>
                <div class="card-body text-secondary"><?= e(__('staffdev.no_trainings')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($trainings as $training): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div><?= e($training['title']) ?> <span class="badge text-bg-light"><?= e($trainingStatuses[$training['status']]) ?></span></div>
                                <div class="small text-secondary">
                                    <?= e($training['provider'] ?? '') ?><?= $training['hours'] ? ' · ' . e(__('staffdev.n_hours', ['hours' => (int) $training['hours']])) : '' ?>
                                    · <span dir="ltr"><?= e($date($training['start_date'])) ?></span>
                                </div>
                            </div>
                            <?php if ($canEdit): ?>
                                <form method="post" action="<?= e(url("/staff-development/$id/trainings/" . (int) $training['id'] . '/delete')) ?>" data-confirm="<?= e(__('app.confirm_delete', ['name' => $training['title']])) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $training['title']])) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <form class="card-body border-top row g-2" method="post" action="<?= e(url("/staff-development/$id/trainings")) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-12 col-md-7"><?= View::partial('partials/input', ['name' => 'title', 'label' => __('staffdev.training_fields.title'), 'required' => true, 'attrs' => 'maxlength="190"']) ?></div>
                    <div class="col-12 col-md-5"><?= View::partial('partials/input', ['name' => 'provider', 'label' => __('staffdev.training_fields.provider'), 'attrs' => 'maxlength="150"']) ?></div>
                    <div class="col-4"><?= View::partial('partials/input', ['name' => 'hours', 'label' => __('staffdev.training_fields.hours'), 'type' => 'number', 'attrs' => 'min="1" max="2000"']) ?></div>
                    <div class="col-8 col-md-4"><?= View::partial('partials/input', ['name' => 'start_date', 'label' => __('staffdev.training_fields.start_date'), 'type' => 'date']) ?></div>
                    <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'status', 'label' => __('staffdev.training_fields.status'), 'type' => 'select', 'required' => true, 'value' => 'planned', 'options' => $trainingStatuses]) ?></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($own): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('staffdev.shared_observations')) ?></h2></div>
                <?php if ($observations === []): ?>
                    <div class="card-body text-secondary"><?= e(__('staffdev.no_observations')) ?></div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($observations as $observation): ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between gap-2">
                                    <strong><?= e(__('observations.types.' . $observation['record_type'])) ?> · <?= e($observation['observer_name'] ?? '—') ?></strong>
                                    <time class="small text-secondary" dir="ltr"><?= e($date($observation['observed_on'])) ?></time>
                                </div>
                                <?php foreach (['strengths', 'areas_for_growth', 'feedback'] as $field): ?>
                                    <?php if ($observation[$field]): ?>
                                        <div class="small text-secondary mt-2"><?= e(__('observations.fields.' . $field)) ?></div>
                                        <div class="prewrap"><?= e($observation[$field]) ?></div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
