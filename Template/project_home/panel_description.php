<div class="page-header"><h2><?= t('Description') ?></h2></div>
<?php if (trim((string) $project['description']) === ''): ?>
    <?= $this->render('Steward:project_home/empty') ?>
<?php else: ?>
    <article class="markdown"><?= $this->text->markdown($project['description']) ?></article>
<?php endif ?>
