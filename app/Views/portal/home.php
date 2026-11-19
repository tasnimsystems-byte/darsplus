<?php
use App\Core\View;

/** @var array $student @var array $children @var array $week @var array $periods @var array $days @var int $today @var array $latest @var array $homework @var int $absentWeek */
View::share('title', __('portal.home'));
$pct = static fn ($score, $max): string => $score === null || (float) $max <= 0 ? '—' : rtrim(rtrim(number_format((float) $score, 1, '.', ''), '0'), '.');
?>
<?= View::partial('portal/_header', ['student' => $student, 'children' => $children, 'heading' => __('portal.hello', ['name' => $student['first_name']]), 'back' => '/me']) ?>

<?php foreach ($live as $meeting): ?>
    <div class="alert live-banner d-flex flex-wrap align-items-center gap-3" role="status">
        <span class="live-dot" aria-hidden="true"></span>
        <strong class="flex-grow-1"><?= e(__('online.live_banner', ['subject' => $meeting['subject_name'], 'teacher' => $meeting['teacher_name']])) ?></strong>
        <a class="btn btn-primary" href="<?= e(url('/online/' . (int) $meeting['id'])) ?>"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i> <?= e(__('online.join_short')) ?></a>
    </div>
<?php endforeach; ?>

<?php if ($absentWeek > 0): ?>
    <div class="alert alert-warning" role="note"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> <?= e(__('portal.absent_week', ['count' => $absentWeek])) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-xl-8">
        <section class="card" aria-labelledby="pt-week">
            <div class="card-header"><h2 class="h6 mb-0" id="pt-week"><i class="bi bi-calendar-week" aria-hidden="true"></i> <?= e(__('portal.my_week')) ?></h2></div>
            <?php if ($week === []): ?>
                <div class="empty-state"><i class="bi bi-calendar-x" aria-hidden="true"></i><p class="mb-0"><?= e(__('portal.no_timetable')) ?></p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered timetable-grid mb-0">
                        <caption class="visually-hidden"><?= e(__('portal.my_week')) ?></caption>
                        <thead><tr>
                            <th scope="col"><?= e(__('timetable.period')) ?></th>
                            <?php foreach ($days as $day): ?>
                                <th scope="col" class="<?= $day === $today ? 'timetable-today' : '' ?>"<?= $day === $today ? ' aria-current="date"' : '' ?>><?= e(__('timetable.days.' . $day)) ?></th>
                            <?php endforeach; ?>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($periods as $number => $period): ?>
                            <?php if ($period['is_break']): ?>
                                <tr class="timetable-break"><th scope="row"><?= e($period['name']) ?></th><td colspan="<?= count($days) ?>"></td></tr>
                                <?php continue; ?>
                            <?php endif; ?>
                            <tr>
                                <th scope="row" class="text-nowrap"><?= e($period['name'] !== '' ? $period['name'] : __('attendance.period', ['n' => $number])) ?></th>
                                <?php foreach ($days as $day): ?>
                                    <td class="<?= $day === $today ? 'timetable-today' : '' ?>">
                                        <?php foreach ($week[$number][$day] ?? [] as $slot): ?>
                                            <div class="timetable-lesson">
                                                <strong><?= e($slot['subject']) ?></strong>
                                                <small class="d-block text-secondary"><?= e($slot['teacher']) ?><?= $slot['room'] !== null ? ' | ' . e($slot['room']) : '' ?></small>
                                            </div>
                                        <?php endforeach; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-12 col-xl-4 d-grid gap-4 align-content-start">
        <section class="card" aria-labelledby="pt-latest">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0" id="pt-latest"><i class="bi bi-journal-check" aria-hidden="true"></i> <?= e(__('portal.latest_grades')) ?></h2>
                <a class="small" href="<?= e(url('/me/grades')) ?>"><?= e(__('portal.all')) ?></a>
            </div>
            <?php if ($latest === []): ?>
                <div class="card-body text-secondary small"><?= e(__('portal.no_grades')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($latest as $row): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                            <span><strong><?= e($row['subject']) ?></strong><small class="d-block text-secondary"><?= e($row['title']) ?></small></span>
                            <span class="portal-score" dir="ltr"><?= $row['result_status'] === 'graded' ? e($pct($row['score'], $row['max_score'])) . '<small>/' . e($pct($row['max_score'], 1)) . '</small>' : e(__('assessments.result_statuses.' . $row['result_status'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="card" aria-labelledby="pt-hw">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0" id="pt-hw"><i class="bi bi-pencil-square" aria-hidden="true"></i> <?= e(__('portal.homework')) ?></h2>
                <a class="small" href="<?= e(url('/me/homework')) ?>"><?= e(__('portal.all')) ?></a>
            </div>
            <?php if ($homework === []): ?>
                <div class="card-body text-secondary small"><?= e(__('portal.no_homework')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($homework as $row): ?>
                        <li class="list-group-item">
                            <strong><?= e($row['subject']) ?></strong> <small class="text-secondary" dir="ltr"><?= e(date('d/m', strtotime($row['lesson_date']))) ?></small>
                            <div class="small text-prewrap"><?= e($row['homework']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
