<?php
use App\Core\View;

/** @var array $classes @var int $classId @var array $students @var array $guardians @var bool $locked */
View::share('title', __('portal.accounts.title'));
$withAccount = count(array_filter($students, static fn ($s) => $s['user_id'] !== null));
$parentsMissing = 0;
foreach ($guardians as $list) {
    foreach ($list as $g) {
        $parentsMissing += $g['user_id'] === null ? 1 : 0;
    }
}
$resetButton = static function (int $userId, string $name): string {
    return '<form method="post" action="' . e(url('/portal-accounts/reset')) . '" class="d-inline" data-confirm="' . e(__('portal.accounts.reset_confirm', ['name' => $name])) . '">'
        . csrf_field() . '<input type="hidden" name="user_id" value="' . $userId . '">'
        . '<button class="btn btn-sm btn-outline-secondary" type="submit" title="' . e(__('portal.accounts.reset_button')) . '" aria-label="' . e(__('portal.accounts.reset_button') . ': ' . $name) . '">'
        . '<i class="bi bi-key" aria-hidden="true"></i></button></form>';
};
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('portal.accounts.title')) ?></h1>
        <p class="text-secondary mb-0"><?= e(__('portal.accounts.subtitle')) ?></p>
    </div>
</div>

<div class="alert alert-warning" role="note"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> <?= e(__('portal.accounts.privacy')) ?></div>

<form class="filter-bar mb-4" method="get" action="<?= e(url('/portal-accounts')) ?>">
    <div class="ff">
        <select class="form-select js-autosubmit" id="pa-class" name="class_id">
            <option value=""><?= e(__('certificates.classes')) ?></option>
            <?php foreach ($classes as $id => $name): ?>
                <option value="<?= (int) $id ?>"<?= $id === $classId ? ' selected' : '' ?>><?= e($name) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="ff-label" for="pa-class"><?= e(__('certificates.classes')) ?></label>
    </div>
    <noscript><button class="btn btn-outline-primary" type="submit"><?= e(__('timetable.show')) ?></button></noscript>
</form>

<?php if ($classId !== 0): ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <form method="post" action="<?= e(url('/portal-accounts/students')) ?>" data-confirm="<?= e(__('portal.accounts.create_students_confirm', ['count' => count($students) - $withAccount])) ?>">
            <?= csrf_field() ?><input type="hidden" name="class_id" value="<?= (int) $classId ?>">
            <button class="btn btn-primary" type="submit"<?= count($students) === $withAccount || $locked ? ' disabled' : '' ?>>
                <i class="bi bi-person-plus" aria-hidden="true"></i> <?= e(__('portal.accounts.create_students', ['count' => count($students) - $withAccount])) ?>
            </button>
        </form>
        <form method="post" action="<?= e(url('/portal-accounts/parents')) ?>" data-confirm="<?= e(__('portal.accounts.create_parents_confirm', ['count' => $parentsMissing])) ?>">
            <?= csrf_field() ?><input type="hidden" name="class_id" value="<?= (int) $classId ?>">
            <button class="btn btn-outline-primary" type="submit"<?= $parentsMissing === 0 || $locked ? ' disabled' : '' ?>>
                <i class="bi bi-people" aria-hidden="true"></i> <?= e(__('portal.accounts.create_parents', ['count' => $parentsMissing])) ?>
            </button>
        </form>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0 table-stack">
                <caption class="visually-hidden"><?= e(__('portal.accounts.title')) ?></caption>
                <thead><tr>
                    <th scope="col"><?= e(__('gradebook.student')) ?></th>
                    <th scope="col"><?= e(__('portal.accounts.student_account')) ?></th>
                    <th scope="col"><?= e(__('portal.accounts.parent_accounts')) ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($students as $s): ?>
                    <?php $name = $s['last_name'] . ' ' . $s['first_name']; ?>
                    <tr>
                        <th scope="row" class="fw-normal"><?= e($name) ?></th>
                        <td data-label="<?= e(__('portal.accounts.student_account')) ?>">
                            <?php if ($s['user_id'] !== null): ?>
                                <code dir="ltr"><?= e((string) $s['username']) ?></code>
                                <span class="small text-secondary"><?= e($s['last_login_at'] !== null ? __('portal.accounts.last_login', ['date' => date('d/m/Y', strtotime($s['last_login_at']))]) : __('portal.accounts.never')) ?></span>
                                <?= $resetButton((int) $s['user_id'], $name) ?>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="<?= e(__('portal.accounts.parent_accounts')) ?>">
                            <?php foreach ($guardians[(int) $s['id']] ?? [] as $g): ?>
                                <div class="small">
                                    <?= e($g['full_name']) ?><?= $g['relation'] ? ' (' . e($g['relation']) . ')' : '' ?>:
                                    <?php if ($g['user_id'] !== null): ?>
                                        <code dir="ltr"><?= e((string) $g['username']) ?></code> <?= $resetButton((int) $g['user_id'], $g['full_name']) ?>
                                    <?php else: ?>
                                        <span class="text-secondary"><?= e(__('portal.accounts.no_account')) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (($guardians[(int) $s['id']] ?? []) === []): ?><span class="small text-secondary"><?= e(__('portal.accounts.no_guardians')) ?></span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
