<?php
use App\Core\App;

$session = App::session();
$success = $session->pullFlash('success');
$error = $session->pullFlash('error');
$tempPassword = $session->pullFlash('temp_password');
?>
<?php if ($success): ?>
    <div class="alert alert-success" role="status"><?= e(__((string) $success)) ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger" role="alert"><?= e(__((string) $error)) ?></div>
<?php endif; ?>
<?php if ($tempPassword): ?>
    <div class="alert alert-warning" role="alert">
        <strong><?= e(__('users.temp_password_title')) ?></strong>
        <code class="temp-password" dir="ltr"><?= e($tempPassword) ?></code>
        <div class="small mt-1"><?= e(__('users.temp_password_hint')) ?></div>
    </div>
<?php endif; ?>
