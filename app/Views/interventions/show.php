<?php
use App\Controllers\InterventionController;
use App\Core\View;

View::share('title', $row['difficulty_area']);
$id = (int) $row['id'];
$date = static fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
$today = date('Y-m-d');
$ratings = [];
foreach (InterventionController::RATINGS as $rating) {
    $ratings[$rating] = __('interventions.ratings.' . $rating);
}
$reviewDue = $row['status'] === 'active' && $row['review_date'] !== null && $row['review_date'] <= $today;
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($meta['student_name']) ?> · <?= e($row['difficulty_area']) ?></h1>
        <p class="text-secondary mb-0">
            <span class="badge <?= $row['status'] === 'active' ? 'text-bg-primary' : 'text-bg-light' ?>"><?= e(__('interventions.statuses.' . $row['status'])) ?></span>
            <?= $meta['subject_name'] ? e($meta['subject_name']) . ' · ' : '' ?>
            <?= e(__('interventions.fields.owner_user_id')) ?>: <?= e($meta['owner_name'] ?? '—') ?>
            · <?= e(__('interventions.fields.start_date')) ?>: <span dir="ltr"><?= e($date($row['start_date'])) ?></span>
            · <?= e(__('interventions.fields.review_date')) ?>: <span dir="ltr"><?= e($date($row['review_date'])) ?></span>
            <?php if ($reviewDue): ?><span class="badge text-bg-warning"><?= e(__('interventions.review_due')) ?></span><?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/interventions/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('interventions.edit')) ?></a>
        <?php endif; ?>
        <?php if (can('students.view')): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('/students/' . (int) $row['student_id'])) ?>"><?= e(__('risk.student_card')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/interventions')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="card mb-4">
    <dl class="card-body row mb-0">
        <?php foreach (['situation_description', 'baseline', 'improvement_goal', 'success_measure', 'recommendations'] as $field): ?>
            <?php if ($row[$field]): ?>
                <dt class="col-sm-3"><?= e(__('interventions.fields.' . $field)) ?></dt>
                <dd class="col-sm-9 prewrap"><?= e($row[$field]) ?></dd>
            <?php endif; ?>
        <?php endforeach; ?>
    </dl>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('interventions.actions')) ?></h2></div>
            <?php if ($actions === []): ?>
                <div class="card-body text-secondary"><?= e(__('interventions.no_actions')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($actions as $action): ?>
                        <?php
                        $aid = (int) $action['id'];
                        $done = $action['status'] === 'done';
                        $mayToggle = can('interventions.update') && ($canEdit || (int) $action['owner_user_id'] === $userId);
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="<?= $done ? 'text-decoration-line-through text-secondary' : '' ?>"><?= e($action['description']) ?></div>
                                <div class="small text-secondary">
                                    <?= e($action['owner_name'] ?? '—') ?> · <span dir="ltr"><?= e($date($action['due_date'])) ?></span>
                                    <?php if (!$done && $action['due_date'] !== null && $action['due_date'] < $today): ?><span class="badge text-bg-warning"><?= e(__('tasks.overdue')) ?></span><?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-1">
                                <?php if ($mayToggle): ?>
                                    <form method="post" action="<?= e(url("/interventions/$id/actions/$aid/toggle")) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm <?= $done ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit"
                                                aria-label="<?= e(__($done ? 'goals.reopen_action' : 'goals.complete_action') . ': ' . $action['description']) ?>">
                                            <i class="bi bi-<?= $done ? 'arrow-counterclockwise' : 'check2' ?>" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                    <form method="post" action="<?= e(url("/interventions/$id/actions/$aid/delete")) ?>" data-confirm="<?= e(__('app.confirm_delete', ['name' => $action['description']])) ?>">
                                        <?= csrf_field() ?>
                                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="<?= e(__('app.delete_named', ['name' => $action['description']])) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <div class="card-body border-top">
                    <h3 class="h6"><?= e(__('interventions.add_action')) ?></h3>
                    <form class="row g-2" method="post" action="<?= e(url("/interventions/$id/actions")) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="col-12"><?= View::partial('partials/input', ['name' => 'description', 'label' => __('interventions.action_fields.description'), 'required' => true, 'attrs' => 'maxlength="255"']) ?></div>
                        <div class="col-7"><?= View::partial('partials/input', ['name' => 'owner_user_id', 'label' => __('interventions.action_fields.owner_user_id'), 'type' => 'select', 'options' => ['' => '—'] + $users]) ?></div>
                        <div class="col-5"><?= View::partial('partials/input', ['name' => 'due_date', 'label' => __('interventions.action_fields.due_date'), 'type' => 'date']) ?></div>
                        <div class="col-12"><button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('interventions.timeline')) ?></h2></div>
            <ol class="list-group list-group-flush timeline">
                <?php foreach ($timeline as $event): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between gap-2">
                            <strong><?= e(__('interventions.events.' . $event['type'])) ?><?= isset($event['rating']) ? ' · ' . e($ratings[$event['rating']]) : '' ?></strong>
                            <time class="small text-secondary" dir="ltr"><?= e($date($event['date'])) ?></time>
                        </div>
                        <div class="prewrap"><?= e($event['text']) ?></div>
                        <?php if ($event['by']): ?><div class="small text-secondary"><?= e($event['by']) ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>

    <?php if ($canEdit): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('interventions.add_review')) ?></h2></div>
                <form class="card-body row g-2" method="post" action="<?= e(url("/interventions/$id/reviews")) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-6 col-md-3"><?= View::partial('partials/input', ['name' => 'review_date', 'label' => __('interventions.review_fields.review_date'), 'type' => 'date', 'required' => true, 'value' => $today]) ?></div>
                    <div class="col-6 col-md-3"><?= View::partial('partials/input', ['name' => 'progress_rating', 'label' => __('interventions.review_fields.progress_rating'), 'type' => 'select', 'required' => true, 'options' => ['' => __('app.select_placeholder')] + $ratings]) ?></div>
                    <div class="col-12 col-md-3"><?= View::partial('partials/input', ['name' => 'next_review_date', 'label' => __('interventions.review_fields.next_review_date'), 'type' => 'date']) ?></div>
                    <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'findings', 'label' => __('interventions.review_fields.findings'), 'type' => 'textarea', 'required' => true, 'attrs' => 'maxlength="3000"']) ?></div>
                    <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'next_steps', 'label' => __('interventions.review_fields.next_steps'), 'type' => 'textarea', 'attrs' => 'maxlength="2000"']) ?></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"><?= e(__('interventions.save_review')) ?></button></div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($reviews !== []): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('interventions.reviews')) ?></h2></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <caption class="visually-hidden"><?= e(__('interventions.reviews')) ?></caption>
                        <thead><tr>
                            <th scope="col"><?= e(__('interventions.review_fields.review_date')) ?></th>
                            <th scope="col"><?= e(__('interventions.review_fields.progress_rating')) ?></th>
                            <th scope="col"><?= e(__('interventions.review_fields.findings')) ?></th>
                            <th scope="col"><?= e(__('interventions.review_fields.next_steps')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($reviews as $review): ?>
                            <tr>
                                <th scope="row" class="fw-normal" dir="ltr"><?= e($date($review['review_date'])) ?></th>
                                <td><?= e($ratings[$review['progress_rating']] ?? $review['progress_rating']) ?></td>
                                <td class="prewrap"><?= e($review['findings']) ?></td>
                                <td class="prewrap"><?= e($review['next_steps'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
