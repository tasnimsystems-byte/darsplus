<?php
use App\Core\View;
use App\Services\ReportCards;

/**
 * @var array $terms term id => row (the chosen term and those before it) @var array $cards @var array $year
 * @var int $termId @var array $header @var string $lang
 */
View::share('title', __('certificates.cert_title', ['term' => $terms[$termId]['name'] ?? '', 'year' => $year['name']]));
$num = static fn (?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
$verbal = static fn (?float $v): string => ($key = ReportCards::verbal($v)) === null ? '' : __('certificates.verbal.' . $key);
$showAnnual = count($terms) > 1;
$today = __('certificates.date', [
    'weekday' => __('certificates.days.' . date('w')), 'day' => date('j'), 'month' => __('certificates.months.' . date('n')), 'year' => date('Y'),
]);
$phones = static fn (): string => trim(($header['phone'] !== '' ? __('certificates.phone') . ' ' . $header['phone'] : '')
    . ($header['fax'] !== '' ? '  |  ' . __('certificates.fax') . ' ' . $header['fax'] : ''), ' |');
?>
<?php if ($cards === []): ?>
    <div class="alert alert-warning" role="alert"><?= e(__('certificates.none')) ?></div>
<?php endif; ?>

<?php foreach ($cards as $card): ?>
    <article class="cert" aria-label="<?= e($card['student']['first_name'] . ' ' . $card['student']['last_name']) ?>">
        <header class="cert-head">
            <div class="cert-school" lang="ar">
                <strong><?= e($header['school_name_ar']) ?></strong>
                <?php if ($phones() !== ''): ?><small dir="ltr"><?= e($phones()) ?></small><?php endif; ?>
            </div>
            <div class="cert-logo">
                <?php if ($header['logo'] !== null): ?><img src="<?= e($header['logo']) ?>" alt=""><?php endif; ?>
            </div>
            <div class="cert-school cert-school-end" lang="he">
                <strong><?= e($header['school_name_he']) ?></strong>
                <?php if ($phones() !== ''): ?><small dir="ltr"><?= e($phones()) ?></small><?php endif; ?>
            </div>
        </header>

        <p class="cert-date"><?= e($today) ?></p>
        <h1 class="cert-title"><?= e(__('certificates.cert_title', ['term' => $terms[$termId]['name'], 'year' => $year['name']])) ?></h1>

        <dl class="cert-info">
            <div><dt><?= e(__('certificates.student_name')) ?>:</dt><dd><?= e($card['student']['first_name'] . ' ' . $card['student']['last_name']) ?></dd></div>
            <div><dt><?= e(__('certificates.class')) ?>:</dt><dd><?= e($card['class']) ?></dd></div>
            <div><dt><?= e(__('certificates.student_code')) ?>:</dt><dd dir="ltr"><?= e($card['student']['student_code']) ?></dd></div>
            <div><dt><?= e(__('certificates.homeroom')) ?>:</dt><dd><?= e($card['homeroom'] !== '' ? $card['homeroom'] : '—') ?></dd></div>
        </dl>

        <table class="cert-table">
            <thead>
            <tr>
                <th scope="col"><?= e(__('certificates.subject')) ?></th>
                <?php foreach ($terms as $term): ?>
                    <th scope="col" colspan="2"><?= e($term['name']) ?></th>
                <?php endforeach; ?>
                <?php if ($showAnnual): ?><th scope="col"><?= e(__('certificates.annual')) ?></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if ($card['rows'] === []): ?>
                <tr><td colspan="<?= 2 + count($terms) * 2 ?>" class="cert-empty"><?= e(__('certificates.no_grades')) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($card['rows'] as $row): ?>
                <tr>
                    <th scope="row"><?= e($row['subject']) ?></th>
                    <?php foreach ($row['terms'] as $grade): ?>
                        <td class="cert-num"><?= e($num($grade)) ?></td>
                        <td class="cert-verbal"><?= e($verbal($grade)) ?></td>
                    <?php endforeach; ?>
                    <?php if ($showAnnual): ?><td class="cert-num cert-annual"><?= e($num($row['annual'])) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th scope="row"><?= e(__('certificates.general')) ?></th>
                <?php foreach ($card['totals'] as $total): ?>
                    <td class="cert-num"><?= e($num($total)) ?></td>
                    <td class="cert-verbal"><?= e($verbal($total)) ?></td>
                <?php endforeach; ?>
                <?php if ($showAnnual): ?><td class="cert-num cert-annual"><?= e($num($card['annual'])) ?></td><?php endif; ?>
            </tr>
            </tfoot>
        </table>

        <section class="cert-remarks">
            <div class="cert-remarks-head">
                <strong><?= e(__('certificates.remarks_box')) ?></strong>
                <span><?= e(__('certificates.lates')) ?>: <?= (int) $card['lates'] ?></span>
                <span><?= e(__('certificates.absences')) ?>: <?= (int) $card['absences'] ?></span>
            </div>
            <p class="cert-remarks-text"><?= e($card['remark']) ?></p>
        </section>

        <footer class="cert-signs">
            <div><span class="cert-sign-line"></span><?= e(__('certificates.sign_principal')) ?><?php if ($header['principal_name'] !== ''): ?><small><?= e($header['principal_name']) ?></small><?php endif; ?></div>
            <div><span class="cert-sign-line"></span><?= e(__('certificates.sign_homeroom')) ?><?php if ($card['homeroom'] !== ''): ?><small><?= e($card['homeroom']) ?></small><?php endif; ?></div>
            <div><span class="cert-sign-line"></span><?= e(__('certificates.sign_parents')) ?></div>
        </footer>
    </article>
<?php endforeach; ?>
