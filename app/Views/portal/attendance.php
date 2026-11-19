<?php
use App\Core\View;

/** @var array $student @var array $children @var array $terms @var array|null $term @var array $records @var array $counts */
View::share('title', __('portal.attendance'));
?>
<?= View::partial('portal/_header', ['student' => $student, 'children' => $children, 'heading' => __('portal.attendance'), 'back' => '/me/attendance']) ?>
<?= View::partial('portal/_terms', ['terms' => $terms, 'term' => $term, 'action' => '/me/attendance']) ?>

<div class="row g-3 mb-4">
    <?php foreach (['absent' => 'person-x', 'late' => 'clock-history', 'justified' => 'person-check'] as $status => $icon): ?>
        <div class="col-12 col-sm-4">
            <div class="card kpi-card kpi-static h-100">
                <div class="card-body">
                    <div class="kpi-icon"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i></div>
                    <div>
                        <div class="kpi-value"><?= (int) $counts[$status] ?></div>
                        <div class="kpi-label"><?= e(__('portal.counts.' . $status)) ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<p class="small mb-4"><a href="<?= e(url('/me/remarks' . ($term !== null ? '?term_id=' . (int) $term['id'] : ''))) ?>"><i class="bi bi-chat-square-text" aria-hidden="true"></i> <?= e(__('portal.see_remarks')) ?></a></p>

<section class="card" aria-labelledby="pt-records">
    <div class="card-header"><h2 class="h6 mb-0" id="pt-records"><?= e(__('portal.attendance_records')) ?></h2></div>
    <?php if ($records === []): ?>
        <div class="empty-state"><i class="bi bi-emoji-smile" aria-hidden="true"></i><p class="mb-0"><?= e(__('portal.no_absences')) ?></p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 table-stack">
                <caption class="visually-hidden"><?= e(__('portal.attendance_records')) ?></caption>
                <thead><tr>
                    <th scope="col"><?= e(__('portal.date')) ?></th>
                    <th scope="col"><?= e(__('timetable.period')) ?></th>
                    <th scope="col"><?= e(__('portal.subject')) ?></th>
                    <th scope="col"><?= e(__('portal.status')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($records as $row): ?>
                    <tr>
                        <th scope="row" class="fw-normal" dir="ltr"><?= e(date('d/m/Y', strtotime($row['lesson_date']))) ?></th>
                        <td data-label="<?= e(__('timetable.period')) ?>"><?= (int) $row['period_number'] ?></td>
                        <td data-label="<?= e(__('portal.subject')) ?>"><?= e($row['subject']) ?></td>
                        <td data-label="<?= e(__('portal.status')) ?>"><span class="badge att-badge att-<?= e($row['status']) ?>"><?= e(__('attendance.statuses.' . $row['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
