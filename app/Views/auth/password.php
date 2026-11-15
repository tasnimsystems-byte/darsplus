<?php
use App\Core\App;
use App\Core\View;

View::share('title', __('auth.change_password_title'));
?>
<h1 class="h4 mb-3"><?= e(__('auth.change_password_title')) ?></h1>
<?php if ($forced): ?>
    <div class="alert alert-info" role="status"><?= e(__('auth.must_change')) ?></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/password')) ?>" novalidate>
    <?= csrf_field() ?>
    <?= View::partial('partials/input', [
        'name' => 'current_password', 'label' => __('auth.fields.current_password'), 'type' => 'password',
        'required' => true, 'attrs' => 'autocomplete="current-password" dir="ltr"',
    ]) ?>
    <?= View::partial('partials/input', [
        'name' => 'new_password', 'label' => __('auth.fields.new_password'), 'type' => 'password', 'required' => true,
        'help' => __('auth.password_policy', ['min' => App::config('app.password_min_length')]),
        'attrs' => 'autocomplete="new-password" dir="ltr"',
    ]) ?>
    <?= View::partial('partials/input', [
        'name' => 'new_password_confirmation', 'label' => __('auth.fields.new_password_confirmation'),
        'type' => 'password', 'required' => true, 'attrs' => 'autocomplete="new-password" dir="ltr"',
    ]) ?>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" type="submit"><?= e(__('app.save')) ?></button>
        <?php if (!$forced): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('/')) ?>"><?= e(__('app.cancel')) ?></a>
        <?php endif; ?>
    </div>
</form>
<?php if ($forced): ?>
    <form method="post" action="<?= e(url('/logout')) ?>" class="mt-3">
        <?= csrf_field() ?>
        <button class="btn btn-link p-0" type="submit"><?= e(__('nav.logout')) ?></button>
    </form>
<?php endif; ?>
