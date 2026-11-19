<?php
use App\Core\App;
use App\Core\View;

$title = View::shared('title');
?>
<!doctype html>
<html <?= html_attributes() ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ? $title . ' · ' : '') . __('app.name')) ?></title>
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.rtl.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<a class="skip-link visually-hidden-focusable" href="#main"><?= e(__('app.skip_to_content')) ?></a>
<div class="app-shell">
    <?= View::partial('partials/sidebar') ?>
    <div class="app-main">
        <?= View::partial('partials/topbar') ?>
        <main id="main" class="app-content" tabindex="-1">
            <?= View::partial('partials/flash') ?>
            <?= $content ?>
        </main>
        <?= View::partial('partials/footer') ?>
    </div>
</div>
<?= View::partial('partials/confirm-modal') ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?php if (\App\Core\View::shared('path') === '/'): ?>
<script src="<?= e(asset('vendor/chartjs/chart.umd.js')) ?>"></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
