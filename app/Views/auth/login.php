<?php
use App\Core\View;

View::share('title', __('auth.login_title'));
?>
<h1 class="h4 mb-3"><?= e(__('auth.login_title')) ?></h1>
<?php if (error_for('login')): ?>
    <div class="alert alert-danger" role="alert"><?= e(error_for('login')) ?></div>
<?php endif; ?>
<form method="post" action="<?= e(url('/login')) ?>" novalidate>
    <?= csrf_field() ?>
    <?= View::partial('partials/input', [
        'name' => 'username', 'label' => __('auth.fields.username'), 'required' => true,
        'attrs' => 'autocomplete="username" autocapitalize="none" spellcheck="false" dir="ltr" autofocus',
    ]) ?>
    <?= View::partial('partials/input', [
        'name' => 'password', 'label' => __('auth.fields.password'), 'type' => 'password', 'required' => true,
        'attrs' => 'autocomplete="current-password" dir="ltr"',
    ]) ?>
    <button class="btn btn-primary w-100" type="submit"><?= e(__('auth.login_button')) ?></button>
</form>
