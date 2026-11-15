<div class="modal fade" id="confirm-modal" tabindex="-1" aria-labelledby="confirm-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="confirm-modal-title"><?= e(__('app.confirm_title')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= e(__('app.close')) ?>"></button>
            </div>
            <div class="modal-body" id="confirm-modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= e(__('app.cancel')) ?></button>
                <button type="button" class="btn btn-danger" id="confirm-modal-ok"><?= e(__('app.confirm')) ?></button>
            </div>
        </div>
    </div>
</div>
