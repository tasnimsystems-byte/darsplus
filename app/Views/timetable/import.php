<?php
use App\Core\View;

View::share('title', __('timetable.import.title'));
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('timetable.import.title')) ?></h1>
        <p class="text-secondary mb-0"><?= e(__('timetable.year', ['year' => $year['name']])) ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(url('/timetable')) ?>"><?= e(__('app.back')) ?></a>
</div>

<?php if ($plan === null): ?>
    <div class="card form-card mb-4">
        <div class="card-body">
            <p><?= e(__('timetable.import.intro')) ?></p>
            <ol class="small text-secondary">
                <?php foreach ([1, 2, 3] as $step): ?>
                    <li><?= e(__('timetable.import.steps.' . $step)) ?></li>
                <?php endforeach; ?>
            </ol>
            <ul class="small text-secondary">
                <?php foreach (['match', 'assignments', 'replace', 'privacy'] as $rule): ?>
                    <li><?= e(__('timetable.import.rules.' . $rule)) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($year['is_locked']): ?>
                <div class="alert alert-warning mb-0" role="alert"><?= e(__('app.errors.year_locked')) ?></div>
            <?php else: ?>
                <form method="post" action="<?= e(url('/timetable/import')) ?>" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>
                    <?= View::partial('partials/input', [
                        'name' => 'file', 'label' => __('timetable.import.file'), 'type' => 'file', 'required' => true,
                        'help' => __('timetable.import.file_help'), 'attrs' => 'accept=".json,.xlsx,application/json"',
                    ]) ?>
                    <button class="btn btn-primary" type="submit"><?= e(__('timetable.import.check')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($history !== []): ?>
        <div class="card">
            <div class="card-header"><h2 class="h6 mb-0"><?= e(__('timetable.import.history')) ?></h2></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <caption class="visually-hidden"><?= e(__('timetable.import.history')) ?></caption>
                    <thead><tr>
                        <th scope="col"><?= e(__('timetable.import.when')) ?></th>
                        <th scope="col"><?= e(__('timetable.import.file')) ?></th>
                        <th scope="col"><?= e(__('timetable.import.by')) ?></th>
                        <th scope="col"><?= e(__('timetable.import.summary_lessons')) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($history as $row): ?>
                        <?php $summary = json_decode((string) $row['summary'], true) ?: []; ?>
                        <tr>
                            <td dir="ltr" class="text-end"><?= e(date('d/m/Y H:i', strtotime($row['created_at']))) ?></td>
                            <td><?= e($row['source_name']) ?><?= !empty($summary['project']) ? ' (' . e($summary['project']) . ')' : '' ?></td>
                            <td><?= e($row['full_name'] ?? '—') ?></td>
                            <td><?= (int) ($summary['slots'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <?php $c = $plan['counts']; ?>
    <div class="alert alert-info" role="status">
        <?= e(__('timetable.import.preview_note', ['project' => $plan['source']['name'], 'school' => $plan['source']['school_name']])) ?>
    </div>

    <div class="row g-3 mb-3">
        <?php foreach ([
            ['classes', 'classes_new'], ['teachers', 'teachers_new'], ['subjects', 'subjects_new'], ['assignments', 'assignments_new'],
        ] as [$total, $new]): ?>
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body">
                    <div class="kpi-value"><?= (int) $c[$total] ?></div>
                    <div class="kpi-label"><?= e(__('timetable.import.counts.' . $total)) ?></div>
                    <div class="small text-secondary"><?= e(__('timetable.import.new_count', ['count' => (int) $c[$new]])) ?></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <ul class="mb-3">
        <li><?= e(__('timetable.import.summary.lessons', ['lessons' => $c['lessons'], 'slots' => $c['slots'], 'days' => count($plan['days']), 'periods' => count($plan['periods'])])) ?></li>
        <?php if ($c['levels_new'] > 0): ?><li><?= e(__('timetable.import.summary.levels', ['count' => $c['levels_new']])) ?></li><?php endif; ?>
        <?php if ($c['rooms_new'] > 0): ?><li><?= e(__('timetable.import.summary.rooms', ['count' => $c['rooms_new']])) ?></li><?php endif; ?>
        <li><?= e(__('timetable.import.summary.replace')) ?></li>
        <?php if ($plan['not_imported'] !== []): ?>
            <li><?= e(__('timetable.import.summary.not_imported', ['items' => implode(', ', array_map(static fn ($k) => __('timetable.import.not_imported.' . $k), array_keys($plan['not_imported'])))])) ?></li>
        <?php endif; ?>
    </ul>

    <?php foreach ($plan['warnings'] as [$key, $params]): ?>
        <div class="alert alert-warning py-2" role="alert"><?= e(__($key, $params)) ?></div>
    <?php endforeach; ?>

    <?php if ($plan['stale'] !== []): ?>
        <div class="alert alert-secondary" role="note">
            <?= e(__('timetable.import.stale', ['count' => count($plan['stale'])])) ?>
            <ul class="mb-0 small">
                <?php foreach (array_slice($plan['stale'], 0, 30) as $row): ?>
                    <li><?= e($row['class_name'] . ' – ' . $row['subject_name'] . ' – ' . $row['teacher_name'] . ($row['group_name'] !== '' ? ' (' . $row['group_name'] . ')' : '')) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <?php foreach (['classes' => 'name', 'subjects' => 'name', 'teachers' => null] as $section => $field): ?>
            <div class="col-12 col-lg-4">
                <div class="card h-100">
                    <div class="card-header"><h2 class="h6 mb-0"><?= e(__('timetable.import.counts.' . $section)) ?></h2></div>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($plan[$section] as $item): ?>
                            <li class="list-group-item d-flex justify-content-between gap-2">
                                <span><?= e($field !== null ? $item[$field] : trim($item['first_name'] . ' ' . $item['last_name'])) ?></span>
                                <span class="badge <?= $item['local_id'] === null ? 'text-bg-primary' : 'text-bg-light' ?>">
                                    <?= e(__($item['local_id'] === null ? 'timetable.import.will_create' : 'timetable.import.matched')) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <?php if ($c['slots'] > 0): ?>
            <form method="post" action="<?= e(url('/timetable/import/confirm')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-primary" type="submit"><?= e(__('timetable.import.confirm', ['count' => $c['slots']])) ?></button>
            </form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/timetable/import/cancel')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-outline-secondary" type="submit"><?= e(__('app.cancel')) ?></button>
        </form>
    </div>
<?php endif; ?>
