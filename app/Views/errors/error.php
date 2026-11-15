<?php
use App\Core\View;

View::share('title', __('errors.' . $status . '.title'));
?>
<div class="text-center">
    <div class="display-5 text-secondary" aria-hidden="true"><?= (int) $status ?></div>
    <h1 class="h4 mt-2"><?= e(__('errors.' . $status . '.title')) ?></h1>
    <p class="text-secondary"><?= e(__('errors.' . $status . '.text')) ?></p>
    <a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= e(__('errors.back_home')) ?></a>
</div>
