<?php
use App\Core\View;

View::share('title', __('achievements.title'));
$termId = $filters['term_id'] ?? null;
$scopeLabel = $termId !== null ? $options['term_id'][$termId] : __('achievements.all_terms');
$bandLabels = ['0–54', '55–69', '70–84', '85–100'];
$bars = static function (array $data, array $names): array {
    $rows = [];
    foreach ($data as $key => $cell) {
        $rows[] = ['label' => $names[$key] ?? '?', 'value' => $cell['average'], 'note' => __('achievements.n_students', ['count' => $cell['students']])];
    }
    usort($rows, static fn ($a, $b) => strnatcmp($a['label'], $b['label']));
    return $rows;
};
$totalStudents = array_sum($distribution);
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('achievements.title')) ?></h1>
        <p class="text-secondary mb-0"><?= e($year['name']) ?> · <?= e($scopeLabel) ?></p>
    </div>
</div>

<form class="card card-body mb-4" method="get" action="<?= e(url('/achievements')) ?>">
    <div class="row g-2 align-items-end">
        <?php foreach ($options as $name => $choices): ?>
            <div class="col-6 col-md">
                <label class="form-label" for="f-<?= e($name) ?>"><?= e(__('achievements.filters.' . $name)) ?></label>
                <select class="form-select" id="f-<?= e($name) ?>" name="<?= e($name) ?>">
                    <option value=""><?= e(__('app.all')) ?></option>
                    <?php foreach ($choices as $value => $label): ?>
                        <option value="<?= (int) $value ?>"<?= ($filters[$name] ?? null) === (int) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>
        <div class="col-12 col-md-auto d-flex gap-2">
            <button class="btn btn-outline-primary" type="submit"><?= e(__('app.filter')) ?></button>
            <?php if ($filters !== []): ?><a class="btn btn-link" href="<?= e(url('/achievements')) ?>"><?= e(__('app.clear')) ?></a><?php endif; ?>
        </div>
    </div>
</form>

<?php if ($empty): ?>
    <div class="card card-body text-secondary text-center py-5"><?= e(__('achievements.empty')) ?></div>
<?php else: ?>
    <p class="small text-secondary"><?= e(__('achievements.method')) ?></p>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="card kpi-card h-100"><div class="card-body">
                <div class="kpi-icon"><i class="bi bi-graph-up" aria-hidden="true"></i></div>
                <div>
                    <div class="kpi-value"><?= e(format_hours($overall['average'])) ?></div>
                    <div class="kpi-label"><?= e(__('achievements.overall')) ?> · <?= e(__('achievements.n_students', ['count' => $overall['students']])) ?></div>
                </div>
            </div></div>
        </div>
        <div class="col-12 col-md-8">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('achievements.distribution')) ?></h2></div>
                <div class="card-body">
                    <?php
                    $rows = [];
                    foreach ($distribution as $i => $n) {
                        $rows[] = ['label' => $bandLabels[$i], 'value' => $totalStudents ? $n / $totalStudents * 100 : 0, 'note' => __('achievements.n_students', ['count' => $n])];
                    }
                    ?>
                    <?= View::partial('partials/bars', ['rows' => $rows, 'unit' => '%']) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <?php foreach ([['bySubject', 'subject_id', 'by_subject'], ['byClass', null, 'by_class'], ['byLevel', 'grade_level_id', 'by_level']] as [$var, $optionKey, $titleKey]): ?>
            <?php $data = $$var; $names = $optionKey === null ? $classNames : $options[$optionKey]; ?>
            <?php if (count($data) > 0): ?>
                <div class="col-12 col-xl-4">
                    <div class="card h-100">
                        <div class="card-header"><h2 class="h6 mb-0"><?= e(__('achievements.' . $titleKey)) ?></h2></div>
                        <div class="card-body"><?= View::partial('partials/bars', ['rows' => $bars($data, $names)]) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if (count($byTerm) > 1): ?>
        <div class="card mb-4">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('achievements.term_comparison')) ?></h2></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden"><?= e(__('achievements.term_comparison')) ?></caption>
                    <thead><tr>
                        <th scope="col"><?= e(__('achievements.filters.subject_id')) ?></th>
                        <?php foreach ($options['term_id'] as $tid => $termName): ?>
                            <?php if (isset($byTerm[$tid])): ?><th scope="col"><?= e($termName) ?></th><?php endif; ?>
                        <?php endforeach; ?>
                        <th scope="col"><?= e(__('achievements.change')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $termCols = array_values(array_filter(array_keys($options['term_id']), static fn ($tid) => isset($byTerm[$tid])));
                    $lines = [];
                    foreach ($subjectByTerm as $subjectId => $cells) {
                        $lines[$options['subject_id'][$subjectId] ?? '?'] = $cells;
                    }
                    ksort($lines);
                    $lines = [__('achievements.overall') => array_map(static fn ($c) => $c['average'], $byTerm)] + $lines;
                    ?>
                    <?php foreach ($lines as $label => $cells): ?>
                        <?php
                        $first = $cells[$termCols[0]] ?? null;
                        $last = $cells[end($termCols)] ?? null;
                        $change = $first !== null && $last !== null ? round($last - $first, 1) : null;
                        ?>
                        <tr>
                            <th scope="row" class="fw-normal"><?= e($label) ?></th>
                            <?php foreach ($termCols as $tid): ?>
                                <td><?= e(format_hours($cells[$tid] ?? null)) ?></td>
                            <?php endforeach; ?>
                            <td>
                                <?php if ($change === null): ?>—
                                <?php else: ?>
                                    <span class="<?= $change < 0 ? 'text-danger' : ($change > 0 ? 'text-success' : '') ?>" dir="ltr">
                                        <i class="bi bi-<?= $change < 0 ? 'arrow-down' : ($change > 0 ? 'arrow-up' : 'dash') ?>" aria-hidden="true"></i>
                                        <?= e(($change > 0 ? '+' : '') . format_hours($change)) ?>
                                    </span>
                                    <span class="visually-hidden"><?= e(__($change < 0 ? 'achievements.decline' : ($change > 0 ? 'achievements.improvement' : 'achievements.no_change'))) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($students !== []): ?>
        <div class="card mb-4">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('achievements.class_students', ['class' => $options['class_id'][$filters['class_id']]])) ?></h2></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden"><?= e(__('achievements.class_students', ['class' => $options['class_id'][$filters['class_id']]])) ?></caption>
                    <thead><tr>
                        <th scope="col"><?= e(__('assessments.student')) ?></th>
                        <th scope="col"><?= e(__('achievements.average_in', ['scope' => $scopeLabel])) ?></th>
                        <?php if ($comparison): ?><th scope="col"><?= e(__('achievements.trend')) ?></th><?php endif; ?>
                        <?php if ($termId !== null): ?><th scope="col"><?= e(__('achievements.by_subject')) ?></th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <th scope="row" class="fw-normal">
                                <?php if (can('students.view')): ?>
                                    <a href="<?= e(url('/students/' . (int) $student['id'])) ?>"><?= e($student['last_name'] . ' ' . $student['first_name']) ?></a>
                                <?php else: ?>
                                    <?= e($student['last_name'] . ' ' . $student['first_name']) ?>
                                <?php endif; ?>
                            </th>
                            <td><?= e(format_hours($student['average'])) ?></td>
                            <?php if ($comparison): ?>
                                <td>
                                    <?php if ($student['trend'] === null): ?>—
                                    <?php else: $c = $student['trend']['change']; ?>
                                        <span class="<?= $c < 0 ? 'text-danger' : ($c > 0 ? 'text-success' : '') ?>" dir="ltr">
                                            <?= e(format_hours($student['trend']['previous'])) ?> → <?= e(format_hours($student['trend']['current'])) ?>
                                            (<?= e(($c > 0 ? '+' : '') . format_hours($c)) ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <?php if ($termId !== null): ?>
                                <td class="small">
                                    <?php foreach ($student['subjects'] as $subjectId => $average): ?>
                                        <span class="badge <?= $average < 55 ? 'text-bg-danger' : 'text-bg-light' ?>"><?= e(($options['subject_id'][$subjectId] ?? '?') . ' ' . format_hours($average)) ?></span>
                                    <?php endforeach; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($declining !== []): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="h6 mb-0"><?= e(__('achievements.declining', ['from' => $options['term_id'][$comparison[0]], 'to' => $options['term_id'][$comparison[1]]])) ?></h2>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden"><?= e(__('achievements.declining', ['from' => $options['term_id'][$comparison[0]], 'to' => $options['term_id'][$comparison[1]]])) ?></caption>
                    <thead><tr>
                        <th scope="col"><?= e(__('assessments.student')) ?></th>
                        <th scope="col"><?= e(__('students.fields.class_id')) ?></th>
                        <th scope="col"><?= e($options['term_id'][$comparison[0]]) ?></th>
                        <th scope="col"><?= e($options['term_id'][$comparison[1]]) ?></th>
                        <th scope="col"><?= e(__('achievements.change')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($declining as $row): ?>
                        <tr>
                            <th scope="row" class="fw-normal"><?= e($row['last_name'] . ' ' . $row['first_name']) ?></th>
                            <td><?= e($row['class_name']) ?></td>
                            <td><?= e(format_hours($row['previous'])) ?></td>
                            <td><?= e(format_hours($row['current'])) ?></td>
                            <td class="text-danger" dir="ltr"><?= e(format_hours($row['change'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-secondary"><?= e(__('achievements.declining_note')) ?></div>
        </div>
    <?php endif; ?>
<?php endif; ?>
