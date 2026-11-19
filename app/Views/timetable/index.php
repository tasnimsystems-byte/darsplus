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
    <?php if ($week !== []): ?>
        <p class="small text-secondary mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> <?= e(__('timetable.week_lessons_help')) ?></p>
    <?php endif; ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-bordered timetable-grid mb-0">
                <caption class="visually-hidden"><?= e(__('timetable.title') . ' – ' . $subjectTitle) ?></caption>
                <thead><tr>
                    <th scope="col"><?= e(__('timetable.period')) ?></th>
                    <?php foreach ($days as $day): ?>
                        <th scope="col">
                            <?= e(__('timetable.days.' . $day)) ?>
                            <?php if ($week !== []): ?>
                                <small class="d-block text-secondary fw-normal" dir="ltr"><?= e(date('d/m', strtotime($week['sunday'] . " +$day days"))) ?></small>
                            <?php endif; ?>
                        </th>
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
                                    <?php
                                    $sessionId = $slot['session_id'] ?? null;
                                    $openable = $slot['openable'] ?? false;
                                    $isToday = ($slot['date'] ?? null) === ($week['today'] ?? '');
                                    ?>
                                    <div class="timetable-lesson">
                                        <?php if ($sessionId !== null): ?>
                                            <a class="lesson-link" href="<?= e(url('/attendance/sessions/' . (int) $sessionId)) ?>"
                                               title="<?= e(__('timetable.open_lesson', ['class' => $slot['class_name'], 'subject' => $slot['subject_name']])) ?>">
                                                <strong><?= e($slot['subject_name']) ?></strong>
                                                <span class="badge text-bg-success"><i class="bi bi-check2" aria-hidden="true"></i> <?= e(__('timetable.opened')) ?></span>
                                            </a>
                                        <?php elseif ($openable): ?>
                                            <form method="post" action="<?= e(url('/attendance/sessions')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="teaching_assignment_id" value="<?= (int) $slot['teaching_assignment_id'] ?>">
                                                <input type="hidden" name="lesson_date" value="<?= e($slot['date']) ?>">
                                                <input type="hidden" name="period_number" value="<?= (int) $number ?>">
                                                <button class="btn btn-sm <?= $isToday ? 'btn-primary' : 'btn-outline-primary' ?> w-100 text-start lesson-open" type="submit"
                                                        aria-label="<?= e(__('timetable.open_lesson', ['class' => $slot['class_name'], 'subject' => $slot['subject_name']])) ?>">
                                                    <strong><?= e($slot['subject_name']) ?></strong>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <strong><?= e($slot['subject_name']) ?></strong>
                                        <?php endif; ?>
                                        <?php if ($slot['group_name'] !== ''): ?><span class="text-secondary">(<?= e($slot['group_name']) ?>)</span><?php endif; ?>
                                        <small class="d-block">
                                            <?php /* Only a timetable the user may open is a link (a teacher sees only their own and their homeroom class's). */ ?>
                                            <?php if ($view === 'teacher'): ?>
                                                <?php if (isset($classes[(int) $slot['class_id']])): ?>
                                                    <a href="<?= e(url('/timetable?view=class&id=' . (int) $slot['class_id'])) ?>"><?= e($slot['class_name']) ?></a>
                                                <?php else: ?>
                                                    <?= e($slot['class_name']) ?>
                                                <?php endif; ?>
                                            <?php elseif (isset($teachers[(int) $slot['teacher_id']])): ?>
                                                <a href="<?= e(url('/timetable?view=teacher&id=' . (int) $slot['teacher_id'])) ?>"><?= e($slot['teacher_name']) ?></a>
                                            <?php else: ?>
                                                <?= e($slot['teacher_name']) ?>
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
