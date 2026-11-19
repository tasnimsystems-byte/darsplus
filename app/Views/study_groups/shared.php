<?php
use App\Core\View;

/** @var array $shared from SharedLessons::detect() @var bool $canEdit */
View::share('title', __('study_groups.shared.title'));
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('study_groups.shared.title')) ?></h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/study-groups')) ?>"><?= e(__('app.back')) ?></a>
</div>

<div class="alert alert-info" role="note"><?= e(__('study_groups.shared.intro')) ?></div>

<?php if ($shared === []): ?>
    <p class="text-secondary"><?= e(__('study_groups.shared.none')) ?></p>
<?php else: ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <caption class="visually-hidden"><?= e(__('study_groups.shared.title')) ?></caption>
                <thead><tr>
                    <th scope="col"><?= e(__('study_groups.shared.lesson')) ?></th>
                    <th scope="col"><?= e(__('study_groups.shared.classes')) ?></th>
                    <th scope="col"><?= e(__('study_groups.shared.kind')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($shared as $lesson): ?>
                    <tr>
                        <td><strong><?= e($lesson['subject']) ?></strong> · <?= e($lesson['teacher']) ?></td>
                        <td><?= e(implode('، ', $lesson['classes'])) ?></td>
                        <td><span class="badge <?= $lesson['kind'] === 'split' ? 'text-bg-info' : 'text-bg-secondary' ?>"><?= e(__('study_groups.shared.kinds.' . $lesson['kind'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($canEdit): ?>
            <div class="card-footer">
                <p class="small text-secondary"><?= e(__('study_groups.shared.members_note')) ?></p>
                <form method="post" action="<?= e(url('/study-groups/shared')) ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-people" aria-hidden="true"></i> <?= e(__('study_groups.shared.create', ['count' => count($shared)])) ?></button>
                </form>
            </div>
        <?php else: ?>
            <div class="card-footer"><div class="alert alert-warning mb-0" role="alert"><?= e(__('app.errors.year_locked')) ?></div></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
