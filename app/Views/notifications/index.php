<?php
use App\Core\View;

View::share('title', __('notifications.title'));
$hasUnread = (bool) array_filter($notifications, static fn ($n) => $n['read_at'] === null);
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('notifications.title')) ?></h1>
    <?php if ($hasUnread): ?>
        <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-outline-primary" type="submit"><?= e(__('notifications.mark_all')) ?></button>
        </form>
    <?php endif; ?>
</div>

<?php if ($notifications === []): ?>
    <div class="card card-body text-secondary text-center py-5"><?= e(__('notifications.empty')) ?></div>
<?php else: ?>
    <ul class="list-group">
        <?php foreach ($notifications as $notification): ?>
            <?php $unread = $notification['read_at'] === null; ?>
            <li class="list-group-item d-flex flex-wrap justify-content-between align-items-start gap-2<?= $unread ? ' notification-unread' : '' ?>">
                <div>
                    <div class="<?= $unread ? 'fw-semibold' : '' ?>">
                        <?php if ($unread): ?><span class="visually-hidden"><?= e(__('notifications.unread')) ?>: </span><?php endif; ?>
                        <?php if ($notification['link'] && str_starts_with($notification['link'], '/')): ?>
                            <a href="<?= e(url($notification['link'])) ?>"><?= e($notification['title']) ?></a>
                        <?php else: ?>
                            <?= e($notification['title']) ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($notification['body']): ?><div class="small text-secondary"><?= e($notification['body']) ?></div><?php endif; ?>
                    <time class="small text-secondary" dir="ltr"><?= e(format_datetime($notification['created_at'])) ?></time>
                </div>
                <?php if ($unread): ?>
                    <form method="post" action="<?= e(url('/notifications/' . (int) $notification['id'] . '/read')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm btn-outline-secondary" type="submit"><?= e(__('notifications.mark_read')) ?></button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
