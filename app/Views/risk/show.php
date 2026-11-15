<?php
use App\Controllers\RiskController;
use App\Core\View;

View::share('title', __('risk.review_title'));
$id = (int) $row['id'];
$name = $row['last_name'] . ' ' . $row['first_name'];
$badge = ['new' => 'text-bg-warning', 'monitoring' => 'text-bg-info', 'intervention' => 'text-bg-primary', 'dismissed' => 'text-bg-secondary'];
$decisions = [];
foreach (RiskController::DECISIONS as $decision) {
    $decisions[$decision] = __('risk.decisions.' . $decision);
}
$n = static fn ($v) => format_hours($v);
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($name) ?></h1>
        <p class="text-secondary mb-0">
            <span class="badge <?= $badge[$row['review_status']] ?>"><?= e(__('risk.statuses.' . $row['review_status'])) ?></span>
            <?= e($row['class_name']) ?> · <?= e($row['term_name']) ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (can('interventions.create')): ?>
            <a class="btn btn-primary" href="<?= e(url('/interventions/create') . '?indication=' . $id) ?>"><?= e(__('risk.open_intervention')) ?></a>
        <?php endif; ?>
        <?php if (can('students.view')): ?>
            <a class="btn btn-outline-primary" href="<?= e(url('/students/' . (int) $row['student_id'])) ?>"><?= e(__('risk.student_card')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/risk')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('risk.why')) ?></h2></div>
            <div class="card-body">
                <p class="fw-semibold"><?= e($row['criterion_name']) ?></p>
                <?php /* The values that triggered the indication, so the reviewer sees why and not only who. */ ?>
                <?php if ($row['criterion_type'] === 'low_average'): ?>
                    <p><?= e(__('risk.evidence.low_average', ['average' => $n($evidence['average'] ?? null), 'threshold' => $n($evidence['threshold'] ?? null), 'term' => $row['term_name']])) ?></p>
                <?php elseif ($row['criterion_type'] === 'decline'): ?>
                    <p><?= e(__('risk.evidence.decline', ['previous' => $n($evidence['previous'] ?? null), 'current' => $n($evidence['current'] ?? null), 'drop' => $n(abs((float) ($evidence['change'] ?? 0)))])) ?></p>
                <?php elseif ($row['criterion_type'] === 'multi_subject'): ?>
                    <p><?= e(__('risk.evidence.multi_subject', ['threshold' => $n($evidence['threshold'] ?? null), 'term' => $row['term_name']])) ?></p>
                    <ul>
                        <?php foreach ($evidence['subjects'] ?? [] as $subject): ?>
                            <li><?= e($subject['subject']) ?>: <?= e($n($subject['average'])) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($row['criterion_type'] === 'absences'): ?>
                    <p><?= e(__('risk.evidence.absences', ['absences' => (int) ($evidence['absences'] ?? 0), 'min_count' => (int) ($evidence['min_count'] ?? 0), 'term' => $row['term_name']])) ?></p>
                <?php endif; ?>
                <p class="small text-secondary mb-0"><?= e(__('risk.evidence_note', ['date' => date('d/m/Y', strtotime($row['updated_at']))])) ?></p>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('risk.decision')) ?></h2></div>
            <div class="card-body">
                <?php if ($row['reviewed_at'] !== null): ?>
                    <p>
                        <?= e(__('risk.reviewed_by', ['name' => $row['reviewer_name'] ?? '—', 'date' => date('d/m/Y', strtotime($row['reviewed_at']))])) ?>
                    </p>
                    <?php if ($row['review_note']): ?><p><?= nl2br(e($row['review_note'])) ?></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($canReview): ?>
                    <p class="text-secondary small"><?= e(__('risk.decision_note')) ?></p>
                    <form method="post" action="<?= e(url("/risk/$id")) ?>" novalidate>
                        <?= csrf_field() ?>
                        <?= View::partial('partials/input', ['name' => 'decision', 'label' => __('risk.fields.decision'), 'type' => 'select', 'required' => true,
                            'value' => in_array($row['review_status'], RiskController::DECISIONS, true) ? $row['review_status'] : '',
                            'options' => ['' => __('app.select_placeholder')] + $decisions]) ?>
                        <?= View::partial('partials/input', ['name' => 'review_note', 'label' => __('risk.fields.review_note'), 'type' => 'textarea',
                            'value' => $row['review_note'] ?? '', 'help' => __('risk.note_help'), 'attrs' => 'maxlength="2000"']) ?>
                        <button class="btn btn-primary" type="submit"><?= e(__('risk.save_decision')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
