<?php
use App\Controllers\AttendanceController;
use App\Controllers\TimetableController;
use App\Core\View;

View::share('title', __('attendance.session_title'));
$id = (int) $session['id'];
$cancelled = $session['status'] === 'cancelled';
$choices = ['present', ...AttendanceController::STATUSES];
$editAttendance = $canEdit && !$cancelled;

// Counts of this lesson, from what is saved.
$counts = array_fill_keys($choices, 0);
foreach ($students as $student) {
    $counts[$student['status'] ?? 'present']++;
}
$topicOptions = [];
foreach ($topics as $topic) {
    $topicOptions[(int) $topic['id']] = $topic['unit_title'] . ' – ' . $topic['title']
        . ($topic['progress'] === 'done' ? ' ✓' : '');
}
$currentTopic = $session['curriculum_topic_id'] !== null ? (int) $session['curriculum_topic_id'] : null;
$currentProgress = null;
foreach ($topics as $topic) {
    if ((int) $topic['id'] === $currentTopic) {
        $currentProgress = $topic['progress'];
    }
}
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1">
            <?= e($session['class_name']) ?> – <?= e($session['subject_name']) ?>
            <?php if ($session['group_name'] !== ''): ?><small class="text-secondary">(<?= e($session['group_name']) ?>)</small><?php endif; ?>
        </h1>
        <p class="text-secondary mb-0">
            <?= e(__('timetable.days.' . date('w', strtotime($session['lesson_date'])))) ?>
            <span dir="ltr"><?= e(date('d/m/Y', strtotime($session['lesson_date']))) ?></span>
            | <?= e(TimetableController::periodLabel($periods, (int) $session['period_number'])) ?> | <?= e($session['teacher_name']) ?>
            <?php if ($cancelled): ?><span class="badge text-bg-secondary"><?= e(__('attendance.cancelled')) ?></span><?php endif; ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('/')) ?>"><i class="bi bi-calendar-week" aria-hidden="true"></i> <?= e(__('timetable.my_week')) ?></a>
        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url("/attendance/sessions/$id/cancel")) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-outline-secondary" type="submit"><?= e(__($cancelled ? 'attendance.restore' : 'attendance.cancel_lesson')) ?></button>
            </form>
        <?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('/attendance') . '?date=' . $session['lesson_date']) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<?php if (count($sameDay) > 1): ?>
    <nav class="mb-3" aria-label="<?= e(__('attendance.same_day')) ?>">
        <span class="small text-secondary me-1"><?= e(__('attendance.same_day')) ?>:</span>
        <?php foreach ($sameDay as $lesson): ?>
            <?php $isThis = (int) $lesson['period_number'] === (int) $session['period_number'] && (int) $lesson['teaching_assignment_id'] === (int) $session['teaching_assignment_id']; ?>
            <?php $text = (int) $lesson['period_number'] . ' | ' . $lesson['class_name']; ?>
            <?php if ($isThis): ?>
                <span class="badge text-bg-primary" aria-current="page"><?= e($text) ?></span>
            <?php elseif ($lesson['session_id'] !== null): ?>
                <a class="badge text-bg-light border text-decoration-none" href="<?= e(url('/attendance/sessions/' . (int) $lesson['session_id'])) ?>"><i class="bi bi-check2" aria-hidden="true"></i> <?= e($text) ?></a>
            <?php else: ?>
                <span class="badge text-bg-light border text-secondary"><?= e($text) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if ($session['study_group_id'] !== null): ?>
    <div class="alert alert-primary py-2" role="note">
        <i class="bi bi-people" aria-hidden="true"></i>
        <?= e(__('study_groups.joint_banner', ['name' => $session['study_group_name'], 'count' => count($students),
            'classes' => count($groupClasses) > 1 ? ' – ' . implode('، ', $groupClasses) : ''])) ?>
    </div>
<?php endif; ?>
<?php if ($cancelled): ?>
    <div class="alert alert-secondary" role="note"><?= e(__('attendance.cancelled_note')) ?></div>
<?php endif; ?>

<?php
$live = $online['live'];
$showOnline = $live !== null || $online['held'] || ($canEdit && !$cancelled && $online['today']);
?>
<?php if ($showOnline): ?>
    <section class="card online-card mb-3<?= $live !== null ? ' is-live' : '' ?>" aria-labelledby="online-title">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <span class="online-card-icon" aria-hidden="true"><i class="bi bi-camera-video"></i></span>
            <div class="flex-grow-1 min-w-0">
                <h2 class="h6 mb-1" id="online-title">
                    <?= e(__('online.card_title')) ?>
                    <?php if ($live !== null): ?><span class="badge online-live-badge"><?= e(__('online.live_since', ['time' => date('H:i', strtotime($live['started_at']))])) ?></span><?php endif; ?>
                </h2>
                <p class="small text-secondary mb-0">
                    <?php if (!$online['enabled'] && $live === null): ?>
                        <?= e(__('online.card_disabled')) ?>
                    <?php elseif ($online['held'] && $students !== []): ?>
                        <?= e(__('online.joined_count', ['count' => count(array_intersect_key($online['joined'], array_flip(array_map(static fn ($s) => (int) $s['id'], $students)))), 'total' => count($students)])) ?>
                    <?php else: ?>
                        <?= e(__('online.card_help')) ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($live !== null && $online['enabled']): ?>
                    <a class="btn btn-primary" href="<?= e(url('/online/' . (int) $live['id'])) ?>"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i> <?= e(__('online.enter')) ?></a>
                    <?php if ($canEdit): ?>
                        <form method="post" action="<?= e(url('/online/' . (int) $live['id'] . '/end')) ?>" data-confirm="<?= e(__('online.end_confirm')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-outline-danger" type="submit"><i class="bi bi-stop-circle" aria-hidden="true"></i> <?= e(__('online.end')) ?></button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($canEdit && !$cancelled && $online['today'] && $online['enabled']): ?>
                    <form method="post" action="<?= e(url("/attendance/sessions/$id/online")) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-camera-video" aria-hidden="true"></i> <?= e(__('online.start')) ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($online['held'] && $editAttendance): ?>
                    <button class="btn btn-outline-secondary" type="button" data-online-mark-absent title="<?= e(__('online.mark_absent_help')) ?>"><i class="bi bi-person-x" aria-hidden="true"></i> <?= e(__('online.mark_absent')) ?></button>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<form method="post" action="<?= e(url("/attendance/sessions/$id")) ?>">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <?php if (!$cancelled): ?>
                <div class="d-flex flex-wrap gap-2 mb-2" role="status">
                    <span class="badge text-bg-light border"><?= e(__('attendance.total_students', ['count' => count($students)])) ?></span>
                    <?php foreach ($choices as $choice): ?>
                        <span class="badge att-badge att-<?= e($choice) ?>"><?= e(__('attendance.statuses.' . $choice)) ?>: <span data-att-count="<?= e($choice) ?>"><?= (int) $counts[$choice] ?></span></span>
                    <?php endforeach; ?>
                </div>
                <?php if ($editAttendance): ?>
                    <p class="small text-secondary mb-2"><?= e(__('attendance.dots_help', ['period' => $dayPeriods[(int) $session['period_number']]['label'] ?? (int) $session['period_number']])) ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <div class="card mb-3">
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('attendance.students_title')) ?></h2></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 attendance-table" data-att-labels="<?= e(json_encode(array_combine($choices, array_map(static fn ($c) => __('attendance.statuses.' . $c), $choices)), JSON_UNESCAPED_UNICODE)) ?>" data-att-hint="<?= e(__('attendance.click_to_change')) ?>">
                        <caption class="visually-hidden"><?= e(__('attendance.session_title')) ?></caption>
                        <thead><tr>
                            <th scope="col">#</th>
                            <th scope="col"><?= e(__('assessments.student')) ?></th>
                            <th scope="col">
                                <?= e(__('attendance.day_title')) ?>
                            </th>
                            <th scope="col"><?= e(__('attendance.remark')) ?></th>
                        </tr></thead>
                        <tbody>
                        <?php if ($students === []): ?>
                            <tr><td colspan="4" class="text-center text-secondary py-4"><?= e(__('classes.no_students')) ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($students as $i => $student): ?>
                            <?php
                            $sid = (int) $student['id'];
                            $name = $student['last_name'] . ' ' . $student['first_name'];
                            $current = $student['status'] ?? 'present';
                            $past = $history[$sid] ?? null;
                            $joined = $online['held'] ? isset($online['joined'][$sid]) : null;
                            ?>
                            <tr<?= $joined === null ? '' : ' data-online-joined="' . ($joined ? '1' : '0') . '"' ?>>
                                <td class="text-secondary small"><?= $i + 1 ?></td>
                                <th scope="row" class="fw-normal" id="st-<?= $sid ?>">
                                    <?php if (can('students.view')): ?>
                                        <a href="<?= e(url('/students/' . $sid)) ?>"><?= e($name) ?></a>
                                    <?php else: ?>
                                        <?= e($name) ?>
                                    <?php endif; ?>
                                    <?php if ($joined !== null): ?>
                                        <span class="badge online-joined-badge<?= $joined ? ' is-joined' : '' ?>"><i class="bi bi-<?= $joined ? 'camera-video' : 'camera-video-off' ?>" aria-hidden="true"></i> <?= e(__($joined ? 'online.joined_badge' : 'online.not_joined_badge')) ?></span>
                                    <?php endif; ?>
                                    <?php if (count($groupClasses) > 1 && ($student['class_name'] ?? null) !== null): ?>
                                        <span class="badge text-bg-light border fw-normal"><?= e($student['class_name']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($past !== null && ((int) $past['absences'] > 0 || (int) $past['lates'] > 0)): ?>
                                        <small class="d-block text-secondary"><?= e(__('attendance.so_far', ['absences' => (int) $past['absences'], 'lates' => (int) $past['lates']])) ?></small>
                                    <?php endif; ?>
                                    <?php if ($past !== null && (int) $past['remarks'] > 0): ?>
                                        <small class="d-block text-danger"><?= e(__('attendance.remarks_so_far', ['count' => (int) $past['remarks']])) ?></small>
                                    <?php endif; ?>
                                </th>
                                <td>
                                    <div class="att-dots" role="group" aria-labelledby="st-<?= $sid ?>">
                                        <?php foreach ($dayPeriods as $number => $p): ?>
                                            <?php if ($number === (int) $session['period_number'] && !$cancelled): ?>
                                                <?php $text = __('attendance.period_box', ['period' => $p['label'], 'status' => __('attendance.statuses.' . $current)]); ?>
                                                <?php if ($editAttendance): ?>
                                                    <input type="hidden" name="students[<?= $sid ?>][status]" value="<?= e($current) ?>" data-att-input>
                                                    <button type="button" class="att-dot att-current att-<?= e($current) ?>" data-att-cycle data-att-period="<?= e($p['label']) ?>"
                                                            aria-label="<?= e($text . ' – ' . __('attendance.click_to_change')) ?>" title="<?= e($text) ?>"><?= e($p['label']) ?></button>
                                                <?php else: ?>
                                                    <span class="att-dot att-current att-<?= e($current) ?>" role="img" aria-label="<?= e($text) ?>" title="<?= e($text) ?>"><?= e($p['label']) ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php $state = $dayGrid[$sid][$number] ?? 'none'; ?>
                                                <?php $text = __('attendance.period_box', ['period' => $p['label'], 'status' => __('attendance.day_states.' . $state)]); ?>
                                                <span class="att-dot att-<?= e($state) ?>" role="img" aria-label="<?= e($text) ?>" title="<?= e($p['name'] . ': ' . __('attendance.day_states.' . $state)) ?>"><?= e($p['label']) ?></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <?php if ($editAttendance): ?>
                                    <td>
                                        <select class="form-select form-select-sm remark-select<?= in_array($student['remark'], AttendanceController::REMARKS['positive'], true) ? ' remark-positive' : ($student['remark'] !== null ? ' remark-negative' : '') ?>"
                                                name="students[<?= $sid ?>][remark]" aria-label="<?= e(__('attendance.remark') . ': ' . $name) ?>">
                                            <option value=""><?= e(__('attendance.no_remark')) ?></option>
                                            <?php foreach (AttendanceController::REMARKS as $group => $codes): ?>
                                                <optgroup label="<?= e(__('attendance.remark_groups.' . $group)) ?>">
                                                    <?php foreach ($codes as $code): ?>
                                                        <option value="<?= e($code) ?>"<?= $student['remark'] === $code ? ' selected' : '' ?>><?= e(__('attendance.remarks.' . $code)) ?></option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (($student['note'] ?? '') !== ''): ?>
                                            <input type="hidden" name="students[<?= $sid ?>][note]" value="<?= e($student['note']) ?>">
                                            <small class="d-block text-secondary"><?= e($student['note']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                <?php else: ?>
                                    <td>
                                        <?= e($student['remark'] !== null ? __('attendance.remarks.' . $student['remark']) : '') ?>
                                        <?php if (($student['note'] ?? '') !== ''): ?><small class="d-block text-secondary"><?= e($student['note']) ?></small><?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small att-legend">
                    <?php foreach (['present', 'absent', 'late', 'justified', 'none'] as $state): ?>
                        <span><span class="att-dot att-sm att-<?= e($state) ?>" aria-hidden="true"></span> <?= e(__('attendance.day_states.' . $state)) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <?php if ($previous !== null): ?>
                <div class="card mb-3">
                    <div class="card-header"><h2 class="h6 mb-0"><?= e(__('attendance.previous_lesson')) ?></h2></div>
                    <div class="card-body small">
                        <a href="<?= e(url('/attendance/sessions/' . (int) $previous['id'])) ?>">
                            <?= e(__('timetable.days.' . date('w', strtotime($previous['lesson_date'])))) ?>
                            <span dir="ltr"><?= e(date('d/m/Y', strtotime($previous['lesson_date']))) ?></span>
                        </a>
                        <dl class="mb-0 mt-2">
                            <dt><?= e(__('attendance.record.topic')) ?></dt>
                            <dd><?= e($previous['topic'] ?? '—') ?></dd>
                            <dt><?= e(__('attendance.record.homework')) ?></dt>
                            <dd class="mb-0 text-prewrap"><?= e($previous['homework'] ?? '—') ?></dd>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card mb-3">
                <div class="card-header"><h2 class="h6 mb-0"><?= e(__('attendance.record.title')) ?></h2></div>
                <div class="card-body">
                    <?php if ($canEdit): ?>
                        <?= View::partial('partials/input', ['name' => 'topic', 'label' => __('attendance.record.topic'), 'value' => $session['topic'], 'attrs' => 'maxlength="255"']) ?>
                        <?php if ($topicOptions !== []): ?>
                            <div class="row g-2">
                                <div class="col-12 col-md-8">
                                    <?= View::partial('partials/input', ['name' => 'curriculum_topic_id', 'label' => __('attendance.record.curriculum_topic_id'), 'type' => 'select',
                                        'options' => ['' => __('attendance.record.no_topic')] + $topicOptions, 'value' => $currentTopic,
                                        'help' => $canProgress ? __('attendance.record.curriculum_help') : null]) ?>
                                </div>
                                <?php if ($canProgress): ?>
                                    <div class="col-12 col-md-4">
                                        <?= View::partial('partials/input', ['name' => 'topic_progress', 'label' => __('attendance.record.topic_progress'), 'type' => 'select',
                                            'options' => ['in_progress' => __('curriculum.progress_statuses.in_progress'), 'done' => __('curriculum.progress_statuses.done')],
                                            'value' => $currentProgress === 'done' ? 'done' : 'in_progress']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?= View::partial('partials/input', ['name' => 'homework', 'label' => __('attendance.record.homework'), 'type' => 'textarea', 'value' => $session['homework'], 'attrs' => 'rows="3" maxlength="2000"']) ?>
                        <?= View::partial('partials/input', ['name' => 'lesson_notes', 'label' => __('attendance.record.lesson_notes'), 'type' => 'textarea', 'value' => $session['lesson_notes'],
                            'help' => __('attendance.record.notes_help'), 'attrs' => 'rows="3" maxlength="2000"']) ?>
                    <?php else: ?>
                        <dl class="mb-0">
                            <dt><?= e(__('attendance.record.topic')) ?></dt><dd><?= e($session['topic'] ?? '—') ?></dd>
                            <?php if ($currentTopic !== null && isset($topicOptions[$currentTopic])): ?>
                                <dt><?= e(__('attendance.record.curriculum_topic_id')) ?></dt><dd><?= e($topicOptions[$currentTopic]) ?></dd>
                            <?php endif; ?>
                            <dt><?= e(__('attendance.record.homework')) ?></dt><dd class="text-prewrap"><?= e($session['homework'] ?? '—') ?></dd>
                            <dt><?= e(__('attendance.record.lesson_notes')) ?></dt><dd class="mb-0 text-prewrap"><?= e($session['lesson_notes'] ?? '—') ?></dd>
                        </dl>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php if ($canEdit): ?>
        <div class="sticky-save">
            <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-save" aria-hidden="true"></i> <?= e(__($cancelled ? 'attendance.save_record' : 'attendance.save')) ?></button>
        </div>
    <?php endif; ?>
</form>
