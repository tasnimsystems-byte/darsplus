<?php
use App\Core\View;

View::share('title', $goal['title']);
$id = (int) $goal['id'];
$badge = ['not_started' => 'text-bg-light', 'in_progress' => 'text-bg-primary', 'completed' => 'text-bg-success',
    'delayed' => 'text-bg-warning', 'needs_intervention' => 'text-bg-danger'];
$date = static fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
$today = date('Y-m-d');
$hasActions = $actions !== [];
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($goal['title']) ?></h1>
        <p class="text-secondary mb-0">
            <span class="badge <?= $badge[$goal['display_status']] ?>"><?= e(__('goals.statuses.' . $goal['display_status'])) ?></span>
            <?= e($meta['objective_title']) ?><?= $meta['domain_name'] ? ' · ' . e($meta['domain_name']) : '' ?>
            · <?= e(__('goals.fields.owner_user_id')) ?>: <?= e($meta['owner_name'] ?? '—') ?>
            · <span dir="ltr"><?= e($date($goal['start_date'])) ?> – <?= e($date($goal['end_date'])) ?></span>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/goals/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('goals.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/work-plan')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
            <progress class="progress-wide" max="100" value="<?= (int) $goal['progress_percent'] ?>" aria-label="<?= e(__('goals.fields.progress_percent')) ?>"></progress>
            <strong><?= (int) $goal['progress_percent'] ?>%</strong>
        </div>
        <dl class="row mb-0">
            <?php foreach (['description', 'baseline_description', 'target_description', 'success_measure', 'effectiveness_review'] as $field): ?>
                <?php if ($goal[$field]): ?>
                    <dt class="col-sm-3"><?= e(__('goals.fields.' . $field)) ?></dt>
                    <dd class="col-sm-9"><?= nl2br(e($goal[$field])) ?></dd>
                <?php endif; ?>
            <?php endforeach; ?>
        </dl>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('goals.indicators')) ?></h2></div>
            <?php if ($indicators === []): ?>
                <div class="card-body text-secondary"><?= e(__('goals.no_indicators')) ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <caption class="visually-hidden"><?= e(__('goals.indicators')) ?></caption>
                        <thead><tr>
                            <th scope="col"><?= e(__('goals.indicator_fields.name')) ?></th>
                            <th scope="col"><?= e(__('goals.indicator_fields.baseline_value')) ?></th>
                            <th scope="col"><?= e(__('goals.indicator_fields.target_value')) ?></th>
                            <th scope="col"><?= e(__('goals.indicator_fields.current_value')) ?></th>
                            <?php if ($canEdit): ?><th scope="col" class="text-end"><?= e(__('app.actions')) ?></th><?php endif; ?>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($indicators as $indicator): ?>
                            <?php $iid = (int) $indicator['id']; $num = static fn ($v) => $v === null ? '—' : rtrim(rtrim((string) $v, '0'), '.'); ?>
                            <tr>
                                <th scope="row" class="fw-normal"><?= e($indicator['name']) ?><?= $indicator['unit'] ? ' <span class="text-secondary small">(' . e($indicator['unit']) . ')</span>' : '' ?></th>
                                <td><?= e($num($indicator['baseline_value'])) ?></td>
                                <td><?= e($num($indicator['target_value'])) ?></td>
                                <td>
                                    <?php if ($canEdit): ?>
                                        <form class="d-flex gap-1" method="post" action="<?= e(url("/goals/$id/indicators/$iid")) ?>">
                                            <?= csrf_field() ?>
                                            <input class="form-control form-control-sm input-narrow" name="current_value" dir="ltr" inputmode="decimal" maxlength="12"
                                                   value="<?= e($indicator['current_value'] === null ? '' : $num($indicator['current_value'])) ?>"
                                                   aria-label="<?= e(__('goals.indicator_fields.current_value') . ': ' . $indicator['name']) ?>">
                                            <button class="btn btn-sm btn-outline-primary" type="submit" aria-label="<?= e(__('app.save') . ': ' . $indicator['name']) ?>"><i class="bi bi-check2" aria-hidden="true"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <?= e($num($indicator['current_value'])) ?>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canEdit): ?>
                                    <td class="text-end">
                                        <form method="post" action="<?= e(url("/goals/$id/indicators/$iid/delete")) ?>" data-confirm="<?= e(__('app.confirm_delete', ['name' => $indicator['name']])) ?>">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $indicator['name']])) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <div class="card-body border-top">
                    <h3 class="h6"><?= e(__('goals.add_indicator')) ?></h3>
                    <form class="row g-2" method="post" action="<?= e(url("/goals/$id/indicators")) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="col-12 col-md-8"><?= View::partial('partials/input', ['name' => 'name', 'label' => __('goals.indicator_fields.name'), 'required' => true, 'attrs' => 'maxlength="190"']) ?></div>
                        <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'unit', 'label' => __('goals.indicator_fields.unit'), 'attrs' => 'maxlength="30"']) ?></div>
                        <?php foreach (['baseline_value', 'target_value', 'current_value'] as $field): ?>
                            <div class="col-4"><?= View::partial('partials/input', ['name' => $field, 'label' => __('goals.indicator_fields.' . $field), 'attrs' => 'dir="ltr" inputmode="decimal" maxlength="12"']) ?></div>
                        <?php endforeach; ?>
                        <div class="col-12"><button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('goals.actions')) ?></h2></div>
            <?php if (!$hasActions): ?>
                <div class="card-body text-secondary"><?= e(__('goals.no_actions')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($actions as $action): ?>
                        <?php
                        $aid = (int) $action['id'];
                        $done = $action['status'] === 'done';
                        $late = !$done && $action['due_date'] !== null && $action['due_date'] < $today;
                        $mayToggle = can('work_plan.update') && ($canEdit || (int) $action['owner_user_id'] === $userId);
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="<?= $done ? 'text-decoration-line-through text-secondary' : '' ?>"><?= e($action['title']) ?></div>
                                <div class="small text-secondary">
                                    <?= e($action['owner_name'] ?? '—') ?> · <span dir="ltr"><?= e($date($action['due_date'])) ?></span>
                                    <?php if ($done): ?><span class="badge text-bg-success"><?= e(__('goals.action_done')) ?></span>
                                    <?php elseif ($late): ?><span class="badge text-bg-warning"><?= e(__('goals.statuses.delayed')) ?></span><?php endif; ?>
                                    <?php if ($action['budget'] !== null): ?> · <?= e(__('goals.action_fields.budget')) ?>: <span dir="ltr"><?= e(number_format((float) $action['budget'])) ?></span><?php endif; ?>
                                </div>
                                <?php if ($action['resources']): ?><div class="small"><?= e($action['resources']) ?></div><?php endif; ?>
                            </div>
                            <div class="d-flex gap-1">
                                <?php if ($mayToggle): ?>
                                    <form method="post" action="<?= e(url("/goals/$id/actions/$aid/toggle")) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm <?= $done ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit"
                                                aria-label="<?= e(__($done ? 'goals.reopen_action' : 'goals.complete_action') . ': ' . $action['title']) ?>">
                                            <i class="bi bi-<?= $done ? 'arrow-counterclockwise' : 'check2' ?>" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                    <form method="post" action="<?= e(url("/goals/$id/actions/$aid/delete")) ?>" data-confirm="<?= e(__('app.confirm_delete', ['name' => $action['title']])) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $action['title']])) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <div class="card-body border-top">
                    <h3 class="h6"><?= e(__('goals.add_action')) ?></h3>
                    <form class="row g-2" method="post" action="<?= e(url("/goals/$id/actions")) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="col-12 col-md-7"><?= View::partial('partials/input', ['name' => 'title', 'label' => __('goals.action_fields.title'), 'required' => true, 'attrs' => 'maxlength="190"']) ?></div>
                        <div class="col-12 col-md-5"><?= View::partial('partials/input', ['name' => 'owner_user_id', 'label' => __('goals.action_fields.owner_user_id'), 'type' => 'select', 'options' => ['' => '—'] + $users]) ?></div>
                        <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'start_date', 'label' => __('goals.action_fields.start_date'), 'type' => 'date']) ?></div>
                        <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'due_date', 'label' => __('goals.action_fields.due_date'), 'type' => 'date']) ?></div>
                        <div class="col-12 col-md-4"><?= View::partial('partials/input', ['name' => 'budget', 'label' => __('goals.action_fields.budget'), 'attrs' => 'dir="ltr" inputmode="decimal" maxlength="12"']) ?></div>
                        <div class="col-12"><?= View::partial('partials/input', ['name' => 'resources', 'label' => __('goals.action_fields.resources'), 'attrs' => 'maxlength="1000"']) ?></div>
                        <div class="col-12"><button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('goals.updates')) ?></h2></div>
            <?php if ($canEdit): ?>
                <div class="card-body border-bottom">
                    <form class="row g-2" method="post" action="<?= e(url("/goals/$id/updates")) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="col-6 col-md-4"><?= View::partial('partials/input', ['name' => 'progress_percent', 'label' => __('goals.update_fields.progress_percent'), 'type' => 'number', 'required' => true, 'value' => $goal['progress_percent'], 'attrs' => 'min="0" max="100"', 'help' => $hasActions ? __('goals.progress_from_actions') : null] + ($hasActions ? [] : [])) ?></div>
                        <div class="col-6 col-md-8"><?= View::partial('partials/input', ['name' => 'status', 'label' => __('goals.update_fields.status'), 'type' => 'select', 'required' => true, 'value' => $goal['status'], 'options' => $statuses]) ?></div>
                        <div class="col-12"><?= View::partial('partials/input', ['name' => 'note', 'label' => __('goals.update_fields.note'), 'type' => 'textarea', 'attrs' => 'maxlength="2000"']) ?></div>
                        <div class="col-12"><button class="btn btn-primary" type="submit"><?= e(__('goals.add_update')) ?></button></div>
                    </form>
                </div>
            <?php endif; ?>
            <?php if ($updates === []): ?>
                <div class="card-body text-secondary"><?= e(__('goals.no_updates')) ?></div>
            <?php else: ?>
                <ol class="list-group list-group-flush timeline">
                    <?php foreach ($updates as $update): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between gap-2">
                                <span><strong><?= (int) $update['progress_percent'] ?>%</strong> · <?= e(__('goals.statuses.' . $update['status'])) ?></span>
                                <time class="small text-secondary" dir="ltr"><?= e(format_datetime($update['created_at'])) ?></time>
                            </div>
                            <?php if ($update['note']): ?><div><?= nl2br(e($update['note'])) ?></div><?php endif; ?>
                            <div class="small text-secondary"><?= e($update['full_name'] ?? '—') ?></div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <?= View::partial('partials/entity-documents', ['documents' => $documents, 'entityType' => 'goal', 'entityId' => $id, 'back' => "/goals/$id", 'canUpload' => $canEdit && can('documents.create')]) ?>
    </div>
</div>
