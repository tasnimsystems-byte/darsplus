<?php
use App\Core\View;

View::share('title', __('observations.record'));
$id = (int) $row['id'];
$date = static fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($meta['teacher_name']) ?> · <?= e(__('observations.types.' . $row['record_type'])) ?></h1>
        <p class="text-secondary mb-0">
            <span dir="ltr"><?= e($date($row['observed_on'])) ?></span> · <?= e(__('observations.observer')) ?>: <?= e($meta['observer_name'] ?? '—') ?>
            <span class="badge <?= $row['shared_with_teacher'] ? 'text-bg-success' : 'text-bg-secondary' ?>">
                <?= e(__($row['shared_with_teacher'] ? 'observations.shared' : 'observations.not_shared')) ?>
            </span>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(url("/observations/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('observations.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/observations')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="alert alert-secondary" role="note"><i class="bi bi-shield-lock" aria-hidden="true"></i> <?= e(__('observations.confidential')) ?></div>

<div class="card mb-4">
    <dl class="card-body row mb-0">
        <?php foreach (['strengths', 'areas_for_growth', 'feedback'] as $field): ?>
            <dt class="col-sm-3"><?= e(__('observations.fields.' . $field)) ?></dt>
            <dd class="col-sm-9 prewrap"><?= e($row[$field] ?? '—') ?></dd>
        <?php endforeach; ?>
    </dl>
</div>

<div class="card">
    <div class="card-header"><h2 class="h6 mb-0"><?= e(__('observations.recommendations')) ?></h2></div>
    <?php if ($recommendations === []): ?>
        <div class="card-body text-secondary"><?= e(__('observations.no_recommendations')) ?></div>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($recommendations as $item): ?>
                <?php $done = $item['status'] === 'done'; ?>
                <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="<?= $done ? 'text-decoration-line-through text-secondary' : '' ?>"><?= e($item['recommendation']) ?></div>
                        <div class="small text-secondary">
                            <?= e(__('observations.recommendation_fields.follow_up_date')) ?>: <span dir="ltr"><?= e($date($item['follow_up_date'])) ?></span>
                            <?php if ($item['follow_up_note']): ?> · <?= e($item['follow_up_note']) ?><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($canEdit): ?>
                        <form class="d-flex gap-1" method="post" action="<?= e(url("/observations/$id/recommendations/" . (int) $item['id'])) ?>">
                            <?= csrf_field() ?>
                            <?php if (!$done): ?>
                                <input class="form-control form-control-sm" name="follow_up_note" maxlength="500" placeholder="<?= e(__('observations.follow_up_note')) ?>"
                                       aria-label="<?= e(__('observations.follow_up_note') . ': ' . $item['recommendation']) ?>">
                            <?php endif; ?>
                            <button class="btn btn-sm <?= $done ? 'btn-outline-secondary' : 'btn-outline-success' ?>" type="submit"
                                    aria-label="<?= e(__($done ? 'goals.reopen_action' : 'goals.complete_action') . ': ' . $item['recommendation']) ?>">
                                <i class="bi bi-<?= $done ? 'arrow-counterclockwise' : 'check2' ?>" aria-hidden="true"></i>
                            </button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canEdit): ?>
        <form class="card-body border-top row g-2" method="post" action="<?= e(url("/observations/$id/recommendations")) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="col-12 col-md-7"><?= View::partial('partials/input', ['name' => 'recommendation', 'label' => __('observations.recommendation_fields.recommendation'), 'required' => true, 'attrs' => 'maxlength="500"']) ?></div>
            <div class="col-8 col-md-3"><?= View::partial('partials/input', ['name' => 'follow_up_date', 'label' => __('observations.recommendation_fields.follow_up_date'), 'type' => 'date']) ?></div>
            <div class="col-4 col-md-2 form-inline-action"><button class="btn btn-primary" type="submit"><?= e(__('app.add')) ?></button></div>
        </form>
    <?php endif; ?>
</div>
