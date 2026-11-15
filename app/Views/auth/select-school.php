<?php
use App\Core\View;

View::share('title', __('auth.select_school_title'));
?>
<h1 class="h4 mb-3"><?= e(__('auth.select_school_title')) ?></h1>
<?php if ($schools === []): ?>
    <p class="text-secondary"><?= e(__('auth.no_schools')) ?></p>
<?php else: ?>
    <form method="post" action="<?= e(url('/select-school')) ?>">
        <?= csrf_field() ?>
        <div class="list-group mb-3">
            <?php foreach ($schools as $school): ?>
                <button class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                        type="submit" name="school_id" value="<?= (int) $school['id'] ?>">
                    <span><?= e($school['name']) ?></span>
                    <span class="badge text-bg-light" dir="ltr"><?= e($school['code']) ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </form>
<?php endif; ?>
<form method="post" action="<?= e(url('/logout')) ?>">
    <?= csrf_field() ?>
    <button class="btn btn-link p-0" type="submit"><?= e(__('nav.logout')) ?></button>
</form>
