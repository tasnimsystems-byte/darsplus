<?php
/** @var array $week from App\Services\TeacherWeek::build() */
?>
<section class="card mb-4 my-week" aria-labelledby="my-week-title">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="h5 mb-0" id="my-week-title"><i class="bi bi-calendar-week" aria-hidden="true"></i> <?= e(__('timetable.my_week')) ?></h2>
            <small class="text-secondary">
                <span dir="ltr"><?= e(date('d/m', strtotime($week['dates'][0])) . ' – ' . date('d/m/Y', strtotime($week['dates'][6]))) ?></span>
                | <?= e(__('timetable.week_summary', ['lessons' => $week['lessons'], 'recorded' => $week['recorded']])) ?>
            </small>
        </div>
        <nav class="btn-group btn-group-sm" aria-label="<?= e(__('timetable.week_nav')) ?>">
            <a class="btn btn-outline-secondary" href="<?= e(url('/?week=' . $week['prev'])) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i> <?= e(__('timetable.prev_week')) ?></a>
            <a class="btn btn-outline-secondary" href="<?= e(url('/')) ?>"><?= e(__('timetable.this_week')) ?></a>
            <a class="btn btn-outline-secondary" href="<?= e(url('/?week=' . $week['next'])) ?>"><?= e(__('timetable.next_week')) ?> <i class="bi bi-chevron-left" aria-hidden="true"></i></a>
        </nav>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered timetable-grid mb-0">
            <caption class="visually-hidden"><?= e(__('timetable.my_week')) ?></caption>
            <thead><tr>
                <th scope="col"><?= e(__('timetable.period')) ?></th>
                <?php foreach ($week['days'] as $day): ?>
                    <?php $isToday = $week['dates'][$day] === $week['today']; ?>
                    <th scope="col" class="<?= $isToday ? 'timetable-today' : '' ?>"<?= $isToday ? ' aria-current="date"' : '' ?>>
                        <?= e(__('timetable.days.' . $day)) ?>
                        <small class="d-block fw-normal" dir="ltr"><?= e(date('d/m', strtotime($week['dates'][$day]))) ?></small>
                    </th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($week['periods'] as $number => $period): ?>
                <?php if ($period['is_break']): ?>
                    <tr class="timetable-break"><th scope="row"><?= e($period['name']) ?></th><td colspan="<?= count($week['days']) ?>"></td></tr>
                    <?php continue; ?>
                <?php endif; ?>
                <tr>
                    <th scope="row" class="text-nowrap">
                        <?= e($period['name'] !== '' ? $period['name'] : __('attendance.period', ['n' => $number])) ?>
                        <?php if ($period['start_time'] !== null && $period['end_time'] !== null): ?>
                            <small class="d-block text-secondary fw-normal" dir="ltr"><?= e(substr($period['start_time'], 0, 5) . '–' . substr($period['end_time'], 0, 5)) ?></small>
                        <?php endif; ?>
                    </th>
                    <?php foreach ($week['days'] as $day): ?>
                        <?php $isToday = $week['dates'][$day] === $week['today']; ?>
                        <td class="<?= $isToday ? 'timetable-today' : '' ?>">
                            <?php foreach ($week['grid'][$number][$day] ?? [] as $slot): ?>
                                <?php
                                $session = $slot['session'];
                                $label = $slot['study_group_id'] !== null
                                    ? $slot['study_group_name'] . (count($slot['classes']) > 1 ? ' – ' . implode('، ', $slot['classes']) : '')
                                    : $slot['class_name'] . ($slot['group_name'] !== '' ? ' (' . $slot['group_name'] . ')' : '');
                                $now = $isToday && $week['current'] === $number;
                                ?>
                                <div class="timetable-lesson<?= $now ? ' timetable-now' : '' ?>">
                                    <?php if ($session !== null): ?>
                                        <a class="lesson-link <?= $session['status'] === 'cancelled' ? 'text-secondary' : '' ?>" href="<?= e(url('/attendance/sessions/' . (int) $session['id'])) ?>">
                                            <strong><?= e($label) ?></strong>
                                            <?php if ($session['status'] === 'cancelled'): ?>
                                                <span class="badge text-bg-secondary"><?= e(__('attendance.cancelled')) ?></span>
                                            <?php else: ?>
                                                <span class="badge text-bg-success"><i class="bi bi-check2" aria-hidden="true"></i> <?= e(__('timetable.opened')) ?></span>
                                            <?php endif; ?>
                                        </a>
                                    <?php elseif ($slot['openable']): ?>
                                        <form method="post" action="<?= e(url('/attendance/sessions')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="teaching_assignment_id" value="<?= (int) $slot['teaching_assignment_id'] ?>">
                                            <input type="hidden" name="lesson_date" value="<?= e($slot['date']) ?>">
                                            <input type="hidden" name="period_number" value="<?= (int) $number ?>">
                                            <button class="btn btn-sm <?= $now ? 'btn-primary' : 'btn-outline-primary' ?> w-100 text-start lesson-open" type="submit"
                                                    aria-label="<?= e(__('timetable.open_lesson', ['class' => $label, 'subject' => $slot['subject_name']])) ?>">
                                                <strong><?= e($label) ?></strong>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <strong><?= e($label) ?></strong>
                                    <?php endif; ?>
                                    <small class="d-block text-secondary">
                                        <?= e($slot['subject_name']) ?><?php if ($slot['room_name'] !== null): ?> | <?= e($slot['room_name']) ?><?php endif; ?>
                                        <?php if ($session !== null && $session['status'] !== 'cancelled' && (int) $session['missing'] > 0): ?>
                                            | <?= e(__('timetable.missing_count', ['count' => (int) $session['missing']])) ?>
                                        <?php endif; ?>
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
    <div class="card-footer small text-secondary"><?= e(__('timetable.my_week_help')) ?></div>
</section>
