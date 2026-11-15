<?php
use App\Core\View;

View::share('title', __('reports.title'));
$icons = ['achievements_school' => 'building', 'achievements_level' => 'layers', 'achievements_class' => 'grid-3x3-gap', 'achievements_subject' => 'book',
    'student_progress' => 'person-lines-fill', 'term_comparison' => 'arrow-left-right', 'support_needed' => 'flag', 'attendance' => 'person-check',
    'curriculum' => 'journal-bookmark', 'work_plan' => 'bullseye', 'tasks' => 'check2-square', 'meetings' => 'calendar-event'];
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('reports.title')) ?></h1>
</div>
<p class="text-secondary"><?= e(__('reports.intro')) ?></p>

<?php if ($available === []): ?>
    <div class="card card-body text-secondary text-center py-5"><?= e(__('reports.none_available')) ?></div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($available as $key): ?>
            <div class="col-12 col-md-6 col-xl-4">
                <a class="card kpi-card h-100" href="<?= e(url("/reports/$key")) ?>">
                    <div class="card-body">
                        <div class="kpi-icon"><i class="bi bi-<?= e($icons[$key]) ?>" aria-hidden="true"></i></div>
                        <div>
                            <div class="fw-semibold"><?= e(__('reports.names.' . $key)) ?></div>
                            <div class="kpi-label"><?= e(__('reports.descriptions.' . $key)) ?></div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
