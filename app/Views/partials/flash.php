<?php
use App\Core\App;

/** @var bool|null $inline true on guest pages: the messages stay in the card instead of toasts */
$session = App::session();
$success = $session->pullFlash('success');
$error = $session->pullFlash('error');
$tempPassword = $session->pullFlash('temp_password');
$inline = !empty($inline);
?>
<?php if ($inline): ?>
    <?php if ($success): ?>
        <div class="alert alert-success" role="status"><?= e(__((string) $success)) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?= e(__((string) $error)) ?></div>
    <?php endif; ?>
<?php elseif ($success || $error): ?>
    <div class="toast-stack" aria-live="polite">
        <?php if ($error): ?>
            <div class="toast toast-danger show" role="alert" aria-atomic="true" data-bs-autohide="false">
                <div class="toast-body">
                    <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
                    <span><?= e(__((string) $error)) ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="<?= e(__('app.close')) ?>"></button>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="toast toast-success show" role="status" aria-atomic="true" data-bs-delay="6000">
                <div class="toast-body">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i>
                    <span><?= e(__((string) $success)) ?></span>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="<?= e(__('app.close')) ?>"></button>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($tempPassword): ?>
    <div class="alert alert-warning" role="alert">
        <strong><?= e(__('users.temp_password_title')) ?></strong>
        <code class="temp-password" dir="ltr"><?= e($tempPassword) ?></code>
        <div class="small mt-1"><?= e(__('users.temp_password_hint')) ?></div>
    </div>
<?php endif; ?>