<?php
use App\Core\View;

View::share('title', $title);
?>
<header class="mb-3">
    <h1 class="h3 mb-1"><?= e($title) ?></h1>
    <?php foreach ($meta as $line): ?>
        <div class="text-secondary small"><?= e($line) ?></div>
    <?php endforeach; ?>
</header>
<?= View::partial('reports/table', ['table' => $table, 'title' => $title]) ?>
