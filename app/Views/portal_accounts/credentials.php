<?php
use App\Core\View;

/** @var array $rows */
View::share('title', __('portal.accounts.credentials_title'));
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('portal.accounts.credentials_title')) ?></h1>
        <p class="text-secondary mb-0"><?= e(__('portal.accounts.credentials_help')) ?></p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary js-print" type="button"><i class="bi bi-printer" aria-hidden="true"></i> <?= e(__('app.print')) ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/portal-accounts')) ?>"><?= e(__('app.back')) ?></a>
    </div>
</div>

<?php if ($rows === []): ?>
    <div class="card"><div class="empty-state"><i class="bi bi-check2-all" aria-hidden="true"></i><p class="mb-0"><?= e(__('portal.accounts.nothing_new')) ?></p></div></div>
<?php else: ?>
    <div class="credential-slips">
        <?php foreach ($rows as $row): ?>
            <div class="credential-slip">
                <strong><?= e($row['name']) ?></strong>
                <span class="small text-secondary"><?= e(__('portal.roles.' . $row['role'])) ?><?= $row['class'] !== '' ? ' · ' . e($row['class']) : '' ?></span>
                <dl>
                    <dt><?= e(__('auth.fields.username')) ?></dt><dd dir="ltr"><code><?= e($row['username']) ?></code></dd>
                    <dt><?= e(__('auth.fields.password')) ?></dt><dd dir="ltr"><code><?= e($row['password']) ?></code></dd>
                </dl>
                <small><?= e(__('portal.accounts.slip_note')) ?></small>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
