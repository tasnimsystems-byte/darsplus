<?php
use App\Core\App;
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
    <link rel="stylesheet" href="<?= e(asset('css/fonts.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="print-body">
<main class="print-page" id="main">
    <?= $content ?>
    <div class="print-actions">
        <button class="btn btn-primary js-print" type="button"><?= e(__('app.print')) ?></button>
        <a class="btn btn-outline-secondary js-back" href="<?= e(url('/')) ?>"><?= e(__('app.back')) ?></a>
    </div>
    <?= View::partial('partials/footer') ?>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
