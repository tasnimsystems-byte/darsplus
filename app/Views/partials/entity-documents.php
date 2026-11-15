<?php
use App\Core\View;

/**
 * Documents attached to one record, with an upload form.
 *
 * @var array  $documents  rows from DocumentService::forEntity()
 * @var string $entityType
 * @var int    $entityId
 * @var string $back       local path to return to after uploading
 * @var bool   $canUpload
 */
?>
<div class="card h-100">
    <div class="card-header"><h2 class="h6 mb-0"><?= e(__('documents.attached')) ?></h2></div>
    <?php if ($documents === []): ?>
        <div class="card-body text-secondary"><?= e(__('documents.empty')) ?></div>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($documents as $document): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <span><?= e($document['title']) ?> <span class="small text-secondary" dir="auto"><?= e($document['original_name']) ?></span></span>
                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/documents/' . (int) $document['id'] . '/download')) ?>"
                       aria-label="<?= e(__('documents.download_named', ['name' => $document['title']])) ?>"><i class="bi bi-download" aria-hidden="true"></i></a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($canUpload): ?>
        <div class="card-body border-top">
            <form class="row g-2" method="post" action="<?= e(url('/documents')) ?>" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="entity_type" value="<?= e($entityType) ?>">
                <input type="hidden" name="entity_id" value="<?= (int) $entityId ?>">
                <input type="hidden" name="back" value="<?= e($back) ?>">
                <input type="hidden" name="category" value="work_plan">
                <input type="hidden" name="visibility" value="restricted">
                <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'title', 'label' => __('documents.fields.title'), 'required' => true, 'attrs' => 'maxlength="190"']) ?></div>
                <div class="col-12 col-md-6"><?= View::partial('partials/input', ['name' => 'file', 'label' => __('documents.fields.file'), 'type' => 'file', 'required' => true]) ?></div>
                <div class="col-12"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-upload" aria-hidden="true"></i> <?= e(__('documents.upload_button')) ?></button></div>
            </form>
        </div>
    <?php endif; ?>
</div>
