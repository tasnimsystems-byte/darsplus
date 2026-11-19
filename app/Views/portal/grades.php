<?php
use App\Core\View;
use App\Services\ReportCards;

/**
 * @var array $student @var array $children @var array $terms @var array|null $term @var array $subjects @var float|null $average
 * @var string $mode list | subject | cards @var int $subjectId
 */
View::share('title', __('portal.grades'));
$num = static fn ($v): string => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
$final = static fn (array $subject): string => $subject['final'] === null ? ''
    : '<span class="portal-final' . ($subject['final'] < 55 ? ' is-low' : '') . '" title="' . e(__('portal.term_grade')) . '">'
      . (int) $subject['final'] . '<small>' . e(__('certificates.verbal.' . ReportCards::verbal((float) $subject['final']))) . '</small></span>';
$link = static fn (array $extra): string => url('/me/grades?' . http_build_query(['term_id' => $term['id'] ?? null] + $extra));
?>
<?= View::partial('portal/_header', ['student' => $student, 'children' => $children, 'heading' => __('portal.grades'), 'back' => '/me/grades']) ?>
<?= View::partial('portal/_terms', ['terms' => $terms, 'term' => $term, 'action' => '/me/grades']) ?>

<?php if ($subjects === []): ?>
    <div class="card"><div class="empty-state"><i class="bi bi-journal" aria-hidden="true"></i><p class="mb-0"><?= e(__('portal.no_grades')) ?></p></div></div>
<?php else: ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <?php if ($average !== null): ?>
            <p class="portal-average mb-0"><?= e(__('portal.term_average')) ?>: <strong dir="ltr"><?= e($num($average)) ?></strong></p>
        <?php endif; ?>
        <nav class="btn-group btn-group-sm" aria-label="<?= e(__('portal.views.label')) ?>">
            <?php foreach (['list' => 'list-ul', 'subject' => 'funnel', 'cards' => 'grid-3x2-gap'] as $view => $icon): ?>
                <a class="btn <?= $mode === $view ? 'btn-primary' : 'btn-outline-primary' ?>"<?= $mode === $view ? ' aria-current="page"' : '' ?> href="<?= e($link(['mode' => $view])) ?>">
                    <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i> <?= e(__('portal.views.' . $view)) ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <?php if ($mode === 'list'): ?>
        <!-- Every subject with its grade; a subject opens to show its components and assessments. -->
        <div class="card portal-grade-list">
            <?php foreach ($subjects as $sid => $subject): ?>
                <details class="portal-grade-row" id="subject-<?= (int) $sid ?>">
                    <summary>
                        <span class="portal-grade-name"><i class="bi bi-chevron-down" aria-hidden="true"></i> <?= e($subject['name']) ?></span>
                        <?= $final($subject) ?: '<span class="text-secondary">—</span>' ?>
                    </summary>
                    <div class="portal-grade-detail">
                        <?= View::partial('portal/_subject_body', ['subject' => $subject]) ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>

    <?php elseif ($mode === 'subject'): ?>
        <!-- One subject at a time, chosen from the list. -->
        <form class="mb-3 portal-subject-pick" method="get" action="<?= e(url('/me/grades')) ?>">
            <input type="hidden" name="mode" value="subject">
            <?php if ($term !== null): ?><input type="hidden" name="term_id" value="<?= (int) $term['id'] ?>"><?php endif; ?>
            <div class="ff">
                <select class="form-select js-autosubmit" id="pg-subject" name="subject_id">
                    <?php foreach ($subjects as $sid => $subject): ?>
                        <option value="<?= (int) $sid ?>"<?= (int) $sid === $subjectId ? ' selected' : '' ?>><?= e($subject['name']) ?><?= $subject['final'] !== null ? ' – ' . (int) $subject['final'] : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="ff-label" for="pg-subject"><?= e(__('portal.subject')) ?></label>
            </div>
            <noscript><button class="btn btn-outline-primary mt-2" type="submit"><?= e(__('timetable.show')) ?></button></noscript>
        </form>
        <?php $subject = $subjects[$subjectId]; ?>
        <section class="card portal-subject portal-subject-single">
            <div class="card-header d-flex justify-content-between align-items-center gap-2">
                <h2 class="h5 mb-0"><?= e($subject['name']) ?></h2>
                <?= $final($subject) ?>
            </div>
            <?= View::partial('portal/_subject_body', ['subject' => $subject]) ?>
        </section>

    <?php else: ?>
        <!-- Every subject in full. -->
        <div class="row g-3">
            <?php foreach ($subjects as $subject): ?>
                <div class="col-12 col-md-6 col-xxl-4">
                    <section class="card h-100 portal-subject">
                        <div class="card-header d-flex justify-content-between align-items-center gap-2">
                            <h2 class="h6 mb-0"><?= e($subject['name']) ?></h2>
                            <?= $final($subject) ?>
                        </div>
                        <?= View::partial('portal/_subject_body', ['subject' => $subject]) ?>
                    </section>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="small text-secondary mt-3"><?= e(__('portal.grades_help')) ?></p>
<?php endif; ?>
