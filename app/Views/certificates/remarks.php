<?php
use App\Core\View;

/** @var string $lang @var array $terms @var int $termId @var array $classes @var int $classId @var array $students @var array $remarks @var bool $locked */
View::share('title', __('certificates.remarks'));
$back = url('/certificates?' . http_build_query(['lang' => $lang, 'term_id' => $termId, 'class_id' => $classId]));
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('certificates.remarks')) ?></h1>
        <p class="text-secondary mb-0"><?= e($classes[$classId] . ' · ' . $terms[$termId]) ?></p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e($back) ?>"><?= e(__('app.back')) ?></a>
</div>

<p class="text-secondary"><?= e(__('certificates.remarks_help')) ?></p>

<?php if ($students === []): ?>
    <div class="card"><div class="empty-state"><i class="bi bi-people" aria-hidden="true"></i><p class="mb-0"><?= e(__('classes.no_students')) ?></p></div></div>
<?php else: ?>
    <form method="post" action="<?= e(url('/certificates/remarks')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="lang" value="<?= e($lang) ?>">
        <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
        <input type="hidden" name="class_id" value="<?= (int) $classId ?>">
        <div class="card">
            <ul class="list-group list-group-flush">
                <?php foreach ($students as $i => $student): ?>
                    <?php $sid = (int) $student['id']; ?>
                    <li class="list-group-item py-3">
                        <label class="form-label fw-semibold" for="r-<?= $sid ?>"><?= ($i + 1) . '. ' . e($student['last_name'] . ' ' . $student['first_name']) ?></label>
                        <textarea class="form-control" id="r-<?= $sid ?>" name="remarks[<?= $sid ?>]" rows="2" maxlength="600"<?= $locked ? ' disabled' : '' ?>><?= e((string) ($remarks[$sid] ?? '')) ?></textarea>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$locked): ?>
                <div class="card-footer sticky-save">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> <?= e(__('certificates.save_remarks')) ?></button>
                </div>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>
