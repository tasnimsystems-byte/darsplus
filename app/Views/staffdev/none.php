<?php
use App\Core\View;

View::share('title', __('staffdev.mine'));
?>
<div class="page-header"><h1 class="h3 mb-0"><?= e(__('staffdev.mine')) ?></h1></div>
<div class="card card-body text-secondary text-center py-5"><?= e(__('staffdev.no_teacher_record')) ?></div>
