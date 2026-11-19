<?php
use App\Core\View;

/**
 * @var string $lang @var array<int,string> $terms @var int $termId @var array<int,string> $classes @var int $classId
 * @var array $students @var int $studentId @var array $year @var array $header
 */
View::share('title', __('certificates.title'));
$query = static fn (array $extra = []): string => http_build_query(['lang' => $lang, 'term_id' => $termId, 'class_id' => $classId] + $extra);
?>
<div class="page-header">
    <div>
        <h1 class="h3 mb-1"><?= e(__('certificates.title')) ?></h1>
        <p class="text-secondary mb-0"><?= e(__('certificates.subtitle', ['year' => $year['name']])) ?></p>
    </div>
</div>

<div class="row g-4">
    <!-- Print settings -->
    <div class="col-12 col-lg-4">
        <section class="card cert-panel" aria-labelledby="cert-settings-title">
            <div class="card-body">
                <h2 class="h5 mb-4" id="cert-settings-title"><?= e(__('certificates.settings_title')) ?></h2>
                <form method="get" action="<?= e(url('/certificates')) ?>" class="d-grid gap-4">
                    <div class="ff">
                        <select class="form-select" id="cert-lang" name="lang">
                            <?php foreach (['ar', 'he'] as $code): ?>
                                <option value="<?= e($code) ?>"<?= $lang === $code ? ' selected' : '' ?> lang="<?= e($code) ?>"><?= e(__('certificates.languages.' . $code)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="ff-label" for="cert-lang"><?= e(__('certificates.lang')) ?></label>
                    </div>
                    <div class="ff">
                        <select class="form-select js-autosubmit" id="cert-term" name="term_id">
                            <option value=""><?= e(__('certificates.term')) ?></option>
                            <?php foreach ($terms as $id => $name): ?>
                                <option value="<?= (int) $id ?>"<?= $id === $termId ? ' selected' : '' ?>><?= e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="ff-label" for="cert-term"><?= e(__('certificates.term')) ?></label>
                    </div>
                    <div class="ff">
                        <select class="form-select js-autosubmit" id="cert-class" name="class_id">
                            <option value=""><?= e(__('certificates.classes')) ?></option>
                            <?php foreach ($classes as $id => $name): ?>
                                <option value="<?= (int) $id ?>"<?= $id === $classId ? ' selected' : '' ?>><?= e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label class="ff-label" for="cert-class"><?= e(__('certificates.classes')) ?></label>
                    </div>
                    <?php if ($classId !== 0): ?>
                        <div class="ff">
                            <select class="form-select" id="cert-student" name="student_id">
                                <option value="0"><?= e(__('certificates.all_students', ['count' => count($students)])) ?></option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?= (int) $student['id'] ?>"<?= (int) $student['id'] === $studentId ? ' selected' : '' ?>><?= e($student['last_name'] . ' ' . $student['first_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="ff-label" for="cert-student"><?= e(__('certificates.student')) ?></label>
                        </div>
                    <?php endif; ?>
                    <?php if ($terms === []): ?>
                        <div class="alert alert-warning mb-0" role="alert"><?= e(__('gradebook.no_terms')) ?></div>
                    <?php endif; ?>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary" type="submit" formaction="<?= e(url('/certificates/print')) ?>" formtarget="_blank"
                                <?= $classId === 0 || $termId === 0 ? 'disabled' : '' ?>>
                            <i class="bi bi-printer" aria-hidden="true"></i> <?= e(__('certificates.print')) ?>
                        </button>
                        <?php if ($classId !== 0 && $termId !== 0): ?>
                            <button class="btn btn-outline-primary" type="submit" form="cert-publish"
                                    name="withdraw" value="<?= $publishedAt !== null ? '1' : '0' ?>">
                                <i class="bi bi-<?= $publishedAt !== null ? 'eye-slash' : 'send' ?>" aria-hidden="true"></i>
                                <?= e(__($publishedAt !== null ? 'certificates.withdraw' : 'certificates.publish')) ?>
                            </button>
                            <?php if ($publishedAt !== null): ?>
                                <p class="small text-success mb-0"><i class="bi bi-check2-circle" aria-hidden="true"></i> <?= e(__('certificates.published_at', ['date' => date('d/m/Y', strtotime($publishedAt))])) ?></p>
                            <?php endif; ?>
                            <a class="btn btn-outline-secondary" href="<?= e(url('/certificates/remarks?' . $query())) ?>">
                                <i class="bi bi-chat-square-text" aria-hidden="true"></i> <?= e(__('certificates.remarks')) ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <p class="small text-secondary mb-0"><?= e(__('certificates.print_help')) ?></p>
                </form>
            </div>
            <?php if ($classId !== 0 && $termId !== 0): ?>
                <form id="cert-publish" method="post" action="<?= e(url('/certificates/publish')) ?>" hidden>
                    <?= csrf_field() ?>
                    <input type="hidden" name="lang" value="<?= e($lang) ?>">
                    <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
                    <input type="hidden" name="class_id" value="<?= (int) $classId ?>">
                </form>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-12 col-lg-8">
        <?php if ($classId !== 0): ?>
            <section class="card mb-4" aria-labelledby="cert-students-title">
                <div class="card-header d-flex justify-content-between align-items-center gap-2">
                    <h2 class="h6 mb-0" id="cert-students-title"><?= e(__('certificates.students_of', ['class' => $classes[$classId]])) ?></h2>
                    <span class="badge text-bg-light border"><?= count($students) ?></span>
                </div>
                <?php if ($students === []): ?>
                    <div class="empty-state"><i class="bi bi-people" aria-hidden="true"></i><p class="mb-0"><?= e(__('classes.no_students')) ?></p></div>
                <?php else: ?>
                    <ul class="list-group list-group-flush cert-students">
                        <?php foreach ($students as $student): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                <span><?= e($student['last_name'] . ' ' . $student['first_name']) ?></span>
                                <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"
                                   href="<?= e(url('/certificates/print?' . $query(['student_id' => (int) $student['id']]))) ?>"
                                   aria-label="<?= e(__('certificates.print_one', ['name' => $student['last_name'] . ' ' . $student['first_name']])) ?>">
                                    <i class="bi bi-printer" aria-hidden="true"></i>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <div class="card mb-4"><div class="empty-state"><i class="bi bi-patch-check" aria-hidden="true"></i><p class="mb-0"><?= e(__('certificates.choose_class')) ?></p></div></div>
        <?php endif; ?>

        <!-- Certificate header -->
        <section class="card" id="header" aria-labelledby="cert-header-title">
            <div class="card-header"><h2 class="h6 mb-0" id="cert-header-title"><?= e(__('certificates.header_title')) ?></h2></div>
            <form method="post" action="<?= e(url('/certificates/header')) ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="card-body">
                    <p class="small text-secondary"><?= e(__('certificates.header_help')) ?></p>
                    <div class="row g-4">
                        <?php foreach (['school_name_ar' => 'ar', 'school_name_he' => 'he', 'phone' => null, 'fax' => null, 'principal_name' => null] as $field => $dirLang): ?>
                            <div class="col-12 <?= str_starts_with($field, 'school') || $field === 'principal_name' ? 'col-md-6' : 'col-md-3' ?>">
                                <div class="ff">
                                    <input class="form-control<?= error_for($field) ? ' is-invalid' : '' ?>" id="h-<?= e($field) ?>" name="<?= e($field) ?>"
                                           value="<?= e((string) old($field, $header[$field])) ?>" placeholder="<?= e(__('certificates.header.' . $field)) ?>"
                                           <?= $dirLang !== null ? 'lang="' . $dirLang . '"' : 'dir="ltr"' ?> maxlength="150">
                                    <label class="ff-label" for="h-<?= e($field) ?>"><?= e(__('certificates.header.' . $field)) ?></label>
                                </div>
                                <?php if (error_for($field)): ?><div class="invalid-feedback d-block"><?= e(error_for($field)) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="h-logo"><?= e(__('certificates.header.logo')) ?></label>
                            <input class="form-control<?= error_for('logo') ? ' is-invalid' : '' ?>" type="file" id="h-logo" name="logo" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
                            <div class="form-text"><?= e(__('certificates.header.logo_help')) ?></div>
                            <?php if (error_for('logo')): ?><div class="invalid-feedback d-block"><?= e(error_for('logo')) ?></div><?php endif; ?>
                        </div>
                        <div class="col-12 col-md-6 d-flex align-items-center gap-3">
                            <?php if ($header['logo'] !== null): ?>
                                <img class="cert-logo-preview" src="<?= e($header['logo']) ?>" alt="<?= e(__('certificates.header.logo')) ?>">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="h-remove">
                                    <label class="form-check-label" for="h-remove"><?= e(__('certificates.header.remove_logo')) ?></label>
                                </div>
                            <?php else: ?>
                                <span class="small text-secondary"><?= e(__('certificates.header.no_logo')) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary" type="submit"><?= e(__('certificates.save_header')) ?></button>
                </div>
            </form>
        </section>
    </div>
</div>
