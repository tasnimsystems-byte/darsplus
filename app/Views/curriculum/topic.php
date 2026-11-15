<?php
use App\Core\View;

View::share('title', __('curriculum.edit_topic'));
$id = (int) $plan['id'];
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('curriculum.edit_topic')) ?></h1>
        <p class="text-secondary mb-0"><?= e($plan['title']) ?></p>
    </div>
</div>
<div class="card">
    <form class="card-body" method="post" action="<?= e(url("/curriculum/$id/topics/" . (int) $topic['id'])) ?>" novalidate>
        <?= csrf_field() ?>
        <?= View::partial('curriculum/topic-fields', ['topic' => $topic, 'units' => $units, 'parents' => $parents]) ?>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><?= e(__('app.save')) ?></button>
            <a class="btn btn-outline-secondary" href="<?= e(url("/curriculum/$id")) ?>"><?= e(__('app.cancel')) ?></a>
        </div>
    </form>
</div>
