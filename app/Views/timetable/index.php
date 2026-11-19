<?php
use App\Controllers\TimetableController;

\App\Core\View::share('title', __('timetable.title'));
$subjectTitle = $view === 'teacher' ? ($teachers[$id] ?? '') : ($classes[$id] ?? '');
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('timetable.title')) ?><?= $subjectTitle !== '' ? ' – ' . e($subjectTitle) : '' ?></h1>
        <p class="text-secondary mb-0">
            <?= e(__('timetable.year', ['year' => $year['name']])) ?>
            <?php if ($lastImport !== null): ?>
                | <?= e(__('timetable.last_import', ['date' => date('d/m/Y H:i', strtotime($lastImport['created_at'])), 'source' => $lastImport['source_name']])) ?>
            <?php endif; ?>
        </p>
    </div>
    <?php if (can('structure.update')): ?>
        <a class="btn btn-primary" href="<?= e(url('/timetable/import')) ?>"><i class="bi bi-upload" aria-hidden="true"></i> <?= e(__('timetable.import.title')) ?></a>
    <?php endif; ?>
</div>

<div class="row g-2 align-items-end mb-4">
    <?php foreach (['class' => $classes, 'teacher' => $teachers] as $kind => $options): ?>
        <?php if ($options === []) continue; ?>
        <form class="col-12 col-md-auto d-flex gap-2 align-items-end" method="get" action="<?= e(url('/timetable')) ?>">
            <input type="hidden" name="view" value="<?= e($kind) ?>">
            <div>
                <label class="form-label" for="tt-<?= e($kind) ?>"><?= e(__('timetable.by_' . $kind)) ?></label>
                <select class="form-select" id="tt-<?= e($kind) ?>" name="id">
                    <?php foreach ($options as $optionId => $label): ?>
                        <option value="<?= (int) $optionId ?>" <?= $view === $kind && $optionId === $id ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-outline-primary" type="submit"><?= e(__('timetable.show')) ?></button>
        </form>
    <?php endforeach; ?>
</div>

<?php if ($grid === []): ?>
    <div class="card"><div class="card-body text-secondary">
        <?= e(__($lastImport === null ? 'timetable.empty' : 'timetable.empty_selection')) ?>
        <?php if ($lastImport === null && can('structure.update')): ?>
            <a href="<?= e(url('/timetable/import')) ?>"><?= e(__('timetable.import.title')) ?></a>
        <?php endif; ?>
    </div></div>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-bordered timetable-grid mb-0">
                <caption class="visually-hidden"><?= e(__('timetable.title') . ' – ' . $subjectTitle) ?></caption>
                <thead><tr>
                    <th scope="col"><?= e(__('timetable.period')) ?></th>
                    <?php foreach ($days as $day): ?>
                        <th scope="col"><?= e(__('timetable.days.' . $day)) ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($periods as $number => $period): ?>
                    <?php if ($period['is_break']): ?>
                        <tr class="timetable-break">
                            <th scope="row"><?= e(TimetableController::periodLabel($periods, $number)) ?></th>
                            <td colspan="<?= count($days) ?>"></td>
                        </tr>
                        <?php continue; ?>
                    <?php endif; ?>
                    <tr>
                        <th scope="row" class="text-nowrap">
                            <?= e($period['name'] !== '' ? $period['name'] : __('attendance.period', ['n' => $number])) ?>
                            <?php if ($period['start_time'] !== null && $period['end_time'] !== null): ?>
                                <small class="d-block text-secondary fw-normal" dir="ltr"><?= e(substr($period['start_time'], 0, 5) . '–' . substr($period['end_time'], 0, 5)) ?></small>
                            <?php endif; ?>
                        </th>
                        <?php foreach ($days as $day): ?>
                            <td>
                                <?php foreach ($grid[$number][$day] ?? [] as $slot): ?>
                                    <div class="timetable-lesson">
                                        <strong><?= e($slot['subject_name']) ?></strong>
                                        <?php if ($slot['group_name'] !== ''): ?><span class="text-secondary">(<?= e($slot['group_name']) ?>)</span><?php endif; ?>
                                        <small class="d-block">
                                            <?php if ($view === 'teacher'): ?>
                                                <a href="<?= e(url('/timetable?view=class&id=' . (int) $slot['class_id'])) ?>"><?= e($slot['class_name']) ?></a>
                                            <?php else: ?>
                                                <a href="<?= e(url('/timetable?view=teacher&id=' . (int) $slot['teacher_id'])) ?>"><?= e($slot['teacher_name']) ?></a>
                                            <?php endif; ?>
                                            <?php if ($slot['room_name'] !== null): ?> | <?= e($slot['room_name']) ?><?php endif; ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
