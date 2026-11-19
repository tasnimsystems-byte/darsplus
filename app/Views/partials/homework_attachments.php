<?php
use App\Services\LessonAttachments;

/**
 * The files and links of a lesson's homework.
 *
 * @var array     $attachments rows of lesson_attachments
 * @var bool|null $removable   show a "remove" checkbox per item (the teacher's form)
 */
$removable = $removable ?? false;
?>
<?php if ($attachments !== []): ?>
    <ul class="hw-attachments list-unstyled mb-0">
        <?php foreach ($attachments as $item): ?>
            <?php $aid = (int) $item['id']; ?>
            <li class="hw-attachment">
                <?php if ($item['kind'] === 'file'): ?>
                    <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                    <a href="<?= e(url('/lesson-files/' . $aid)) ?>" download><?= e($item['original_name']) ?></a>
                    <small class="text-secondary" dir="ltr"><?= e(LessonAttachments::humanSize((int) $item['size_bytes'])) ?></small>
                <?php else: ?>
                    <i class="bi bi-link-45deg" aria-hidden="true"></i>
                    <a href="<?= e($item['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($item['title']) ?></a>
                    <small class="text-secondary text-truncate hw-url" dir="ltr"><?= e((string) parse_url($item['url'], PHP_URL_HOST)) ?></small>
                <?php endif; ?>
                <?php if ($removable): ?>
                    <span class="form-check form-check-inline ms-auto me-0 mb-0">
                        <input class="form-check-input" type="checkbox" name="remove_attachments[]" value="<?= $aid ?>" id="rm-att-<?= $aid ?>">
                        <label class="form-check-label small text-danger" for="rm-att-<?= $aid ?>"><?= e(__('attendance.homework.remove')) ?></label>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
