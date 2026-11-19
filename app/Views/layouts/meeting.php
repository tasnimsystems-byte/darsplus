<?php
use App\Core\View;

/** A full-window page for an online lesson: no menu, the meeting fills the screen. */
$title = View::shared('title');
?>
<!doctype html>
<html <?= html_attributes() ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e(($title ? $title . ' · ' : '') . __('app.name')) ?></title>
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.rtl.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="meet-body">
<main id="main" class="meet-shell">
    <?= View::partial('partials/flash') ?>
    <?= $content ?>
</main>
<?= View::partial('partials/confirm-modal') ?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('vendor/livekit/livekit-client.umd.js')) ?>"></script>
<script src="<?= e(asset('js/online-room.js')) ?>"></script>
</body>
</html>
