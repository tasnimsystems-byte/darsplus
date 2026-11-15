<?php
use App\Core\App;
use App\Core\Lang;
use App\Core\View;

$title = View::shared('title');
?>
<!doctype html>
<html lang="<?= e(App::lang()->locale()) ?>" dir="<?= e(App::lang()->direction()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ? $title . ' · ' : '') . __('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.rtl.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="auth-body">
<main class="auth-wrap" id="main">
    <div class="auth-brand">
        <i class="bi bi-mortarboard" aria-hidden="true"></i>
        <span><?= e(__('app.name')) ?></span>
    </div>
    <div class="card auth-card">
        <div class="card-body">
            <?= View::partial('partials/flash') ?>
            <?= $content ?>
        </div>
    </div>
    <form method="post" action="<?= e(url('/locale')) ?>" class="auth-locale">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e((string) View::shared('path')) ?>">
        <span class="visually-hidden" id="auth-locale-label"><?= e(__('app.language')) ?></span>
        <div class="btn-group btn-group-sm" role="group" aria-labelledby="auth-locale-label">
            <?php foreach (Lang::SUPPORTED as $code => $name): ?>
                <button type="submit" name="locale" value="<?= e($code) ?>" lang="<?= e($code) ?>"
                        class="btn btn-outline-secondary<?= App::lang()->locale() === $code ? ' active' : '' ?>"
                        <?= App::lang()->locale() === $code ? 'aria-current="true"' : '' ?>><?= e($name) ?></button>
            <?php endforeach; ?>
        </div>
    </form>
</main>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
