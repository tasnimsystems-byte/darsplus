<?php
use App\Core\View;

View::share('title', __('documents.title'));
$categoryOptions = [];
foreach ($categories as $category) {
    $categoryOptions[$category] = __('documents.categories.' . $category);
}
$formatSize = static fn (int $bytes): string => $bytes >= 1048576
    ? number_format($bytes / 1048576, 1) . ' MB'
    : max(1, (int) round($bytes / 1024)) . ' KB';
?>
<div class="page-header">
    <h1 class="h3 mb-0"><?= e(__('documents.title')) ?></h1>
</div>

<?php if (can('documents.create')): ?>
    <div class="card mb-4">
        <div class="card-header"><h2 class="h6 mb-0"><?= e(__('documents.upload')) ?></h2></div>
        <div class="card-body">
            <form method="post" action="<?= e(url('/documents')) ?>" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6">
                        <?= View::partial('partials/input', [
                            'name' => 'title', 'label' => __('documents.fields.title'), 'required' => true,
                            'attrs' => 'maxlength="190"',
                        ]) ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <?= View::partial('partials/input', [
                            'name' => 'file', 'label' => __('documents.fields.file'), 'type' => 'file', 'required' => true,
                            'help' => __('documents.file_help', ['types' => implode(', ', $extensions), 'max' => $maxMb]),
                            'attrs' => 'accept=".' . e(implode(',.', $extensions)) . '"',
                        ]) ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <?= View::partial('partials/input', [
                            'name' => 'category', 'label' => __('documents.fields.category'), 'type' => 'select',
                            'required' => true, 'value' => 'other', 'options' => $categoryOptions,
                        ]) ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <?= View::partial('partials/input', [
                            'name' => 'visibility', 'label' => __('documents.fields.visibility'), 'type' => 'select',
                            'required' => true, 'value' => 'restricted',
                            'options' => [
                                'restricted' => __('documents.visibility.restricted'),
                                'staff' => __('documents.visibility.staff'),
                            ],
                            'help' => __('documents.visibility_help'),
                        ]) ?>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-upload" aria-hidden="true"></i> <?= e(__('documents.upload_button')) ?>
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden"><?= e(__('documents.title')) ?></caption>
            <thead>
            <tr>
                <th scope="col"><?= e(__('documents.fields.title')) ?></th>
                <th scope="col"><?= e(__('documents.fields.category')) ?></th>
                <th scope="col"><?= e(__('documents.fields.visibility')) ?></th>
                <th scope="col"><?= e(__('documents.fields.size')) ?></th>
                <th scope="col"><?= e(__('documents.fields.uploaded')) ?></th>
                <th scope="col" class="text-end"><?= e(__('app.actions')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($documents === []): ?>
                <tr><td colspan="6" class="text-center text-secondary py-4"><?= e(__('documents.empty')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($documents as $document): ?>
                <?php $id = (int) $document['id']; ?>
                <tr>
                    <th scope="row" class="fw-normal">
                        <?= e($document['title']) ?>
                        <div class="small text-secondary" dir="auto"><?= e($document['original_name']) ?></div>
                    </th>
                    <td><?= e(trans_or('documents.categories.' . $document['category'], $document['category'])) ?></td>
                    <td><?= e(__('documents.visibility.' . $document['visibility'])) ?></td>
                    <td dir="ltr" class="text-end"><?= e($formatSize((int) $document['size_bytes'])) ?></td>
                    <td>
                        <?= e($document['uploader_name'] ?? '—') ?>
                        <div class="small text-secondary"><time dir="ltr"><?= e(format_datetime($document['created_at'])) ?></time></div>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url("/documents/$id/download")) ?>"
                           aria-label="<?= e(__('documents.download_named', ['name' => $document['title']])) ?>">
                            <i class="bi bi-download" aria-hidden="true"></i>
                        </a>
                        <?php if ($document['can_delete']): ?>
                            <form class="d-inline" method="post" action="<?= e(url("/documents/$id/delete")) ?>"
                                  data-confirm="<?= e(__('documents.confirm_delete', ['name' => $document['title']])) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit"
                                        aria-label="<?= e(__('documents.delete_named', ['name' => $document['title']])) ?>">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
