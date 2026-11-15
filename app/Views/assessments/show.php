<?php
use App\Controllers\AssessmentController;
use App\Core\View;

View::share('title', $assessment['title']);
$id = (int) $assessment['id'];
$published = $assessment['status'] === 'published';
$numeric = (bool) $meta['is_numeric'];
$max = format_hours($assessment['max_score']);
$statuses = [];
foreach (AssessmentController::RESULT_STATUSES as $status) {
    $statuses[$status] = __('assessments.result_statuses.' . $status);
}
$bandLabels = ['0–54', '55–69', '70–84', '85–100'];
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e($assessment['title']) ?></h1>
        <?php /* A score is never shown without its subject, term and type. */ ?>
        <p class="text-secondary mb-0">
            <span class="badge <?= $published ? 'text-bg-success' : 'text-bg-light' ?>"><?= e(__('assessments.statuses.' . $assessment['status'])) ?></span>
            <?= e($meta['subject_name']) ?> · <?= e($meta['class_name']) ?> · <?= e($meta['term_name']) ?> · <?= e($meta['type_name']) ?>
            · <span dir="ltr"><?= e(date('d/m/Y', strtotime($assessment['assessment_date']))) ?></span>
            · <?= e($meta['teacher_name']) ?>
            <?php if ($numeric): ?>· <?= e(__('assessments.max_and_weight', ['max' => $max, 'weight' => format_hours($assessment['weight'])])) ?><?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url("/assessments/$id/publish")) ?>"
                  <?= $published ? '' : 'data-confirm="' . e(__('assessments.confirm_publish')) . '"' ?>>
                <?= csrf_field() ?>
                <button class="btn <?= $published ? 'btn-outline-secondary' : 'btn-primary' ?>" type="submit"><?= e(__($published ? 'assessments.unpublish' : 'assessments.publish')) ?></button>
            </form>
            <a class="btn btn-outline-primary" href="<?= e(url("/assessments/$id/edit")) ?>"><i class="bi bi-pencil" aria-hidden="true"></i> <?= e(__('assessments.edit')) ?></a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/assessments')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<?php if (!$published): ?>
    <div class="alert alert-secondary" role="note"><?= e(__('assessments.draft_note')) ?></div>
<?php elseif ($canEdit): ?>
    <div class="alert alert-warning" role="note"><?= e(__('assessments.published_note')) ?></div>
<?php endif; ?>

<?php if ($stats !== null && $numeric): ?>
    <div class="row g-3 mb-4">
        <?php foreach (['count', 'average', 'median', 'min', 'max'] as $key): ?>
            <div class="col-6 col-md">
                <div class="card h-100"><div class="card-body">
                    <div class="kpi-value"><?= e(format_hours($stats[$key])) ?></div>
                    <div class="kpi-label"><?= e(__('assessments.stats.' . $key)) ?></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card mb-4">
        <div class="card-header"><h2 class="h6 mb-0"><?= e(__('assessments.distribution')) ?></h2></div>
        <div class="card-body">
            <?php
            $rows = [];
            foreach ($stats['bands'] as $i => $n) {
                $rows[] = ['label' => $bandLabels[$i], 'value' => $n / $stats['count'] * 100, 'note' => __('assessments.students_count', ['count' => $n])];
            }
            ?>
            <?= View::partial('partials/bars', ['rows' => $rows, 'unit' => '%']) ?>
            <p class="small text-secondary mb-0"><?= e(__('assessments.percent_note')) ?></p>
        </div>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url("/assessments/$id/results")) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="card mb-3">
        <div class="card-header"><h2 class="h6 mb-0"><?= e(__('assessments.sheet')) ?></h2></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <caption class="visually-hidden"><?= e(__('assessments.sheet')) ?></caption>
                <thead><tr>
                    <th scope="col"><?= e(__('assessments.student')) ?></th>
                    <th scope="col"><?= e($numeric ? __('assessments.score_of', ['max' => $max]) : __('assessments.verbal')) ?></th>
                    <th scope="col"><?= e(__('assessments.result_status')) ?></th>
                    <th scope="col"><?= e(__('assessments.comment')) ?></th>
                </tr></thead>
                <tbody>
                <?php if ($roster === []): ?>
                    <tr><td colspan="4" class="text-center text-secondary py-4"><?= e(__('classes.no_students')) ?></td></tr>
                <?php endif; ?>
                <?php foreach ($roster as $student): ?>
                    <?php
                    $sid = (int) $student['id'];
                    $name = $student['last_name'] . ' ' . $student['first_name'];
                    $status = $student['result_status'] ?? 'graded';
                    ?>
                    <tr>
                        <th scope="row" class="fw-normal"><?= e($name) ?></th>
                        <?php if ($canEdit): ?>
                            <td>
                                <?php if ($numeric): ?>
                                    <input class="form-control form-control-sm input-narrow" name="results[<?= $sid ?>][score]" dir="ltr" inputmode="decimal" maxlength="6"
                                           value="<?= e($student['score'] === null ? '' : format_hours($student['score'])) ?>" aria-label="<?= e(__('assessments.score_of', ['max' => $max]) . ': ' . $name) ?>">
                                <?php else: ?>
                                    <input class="form-control form-control-sm" name="results[<?= $sid ?>][verbal]" maxlength="255"
                                           value="<?= e($student['verbal_evaluation'] ?? '') ?>" aria-label="<?= e(__('assessments.verbal') . ': ' . $name) ?>">
                                <?php endif; ?>
                            </td>
                            <td>
                                <select class="form-select form-select-sm" name="results[<?= $sid ?>][status]" aria-label="<?= e(__('assessments.result_status') . ': ' . $name) ?>">
                                    <?php foreach ($statuses as $value => $label): ?>
                                        <option value="<?= e($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input class="form-control form-control-sm" name="results[<?= $sid ?>][comment]" maxlength="500" value="<?= e($student['comment'] ?? '') ?>" aria-label="<?= e(__('assessments.comment') . ': ' . $name) ?>"></td>
                        <?php else: ?>
                            <td dir="auto"><?= e($numeric ? ($student['score'] === null ? '—' : format_hours($student['score'])) : ($student['verbal_evaluation'] ?? '—')) ?></td>
                            <td><?= e($student['result_status'] === null ? '—' : $statuses[$student['result_status']]) ?></td>
                            <td><?= e($student['comment'] ?? '') ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($canEdit && $roster !== []): ?>
        <button class="btn btn-primary" type="submit"><?= e(__('assessments.save_results')) ?></button>
    <?php endif; ?>
</form>
