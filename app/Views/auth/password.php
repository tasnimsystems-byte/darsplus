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
<?php if (\App\Services\OidcClient::enabled() || ($ssoLinkedAt ?? null) !== null): ?>
    <section class="mt-4 pt-3 border-top" aria-labelledby="sso-title">
        <h2 class="h6" id="sso-title"><i class="bi bi-shield-lock" aria-hidden="true"></i> <?= e(__('auth.sso.title')) ?></h2>
        <?php if (($ssoLinkedAt ?? null) !== null): ?>
            <p class="small mb-2"><?= e(__('auth.sso.is_linked', ['date' => date('d/m/Y', strtotime($ssoLinkedAt))])) ?></p>
            <form method="post" action="<?= e(url('/auth/sso/unlink')) ?>" data-confirm="<?= e(__('auth.sso.unlink_confirm')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-danger" type="submit"><?= e(__('auth.sso.unlink')) ?></button>
            </form>
        <?php else: ?>
            <p class="small text-secondary mb-2"><?= e(__('auth.sso.link_help')) ?></p>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/auth/sso?link=1')) ?>"><?= e(__('auth.sso.link')) ?></a>
        <?php endif; ?>
    </section>
<?php endif; ?>
