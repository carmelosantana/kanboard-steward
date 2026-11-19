<div class="page-header"><h2><?= t('Recent activity') ?></h2></div>
<?php if (empty($data)): ?>
    <?= $this->render('Steward:project_home/empty') ?>
<?php else: ?>
    <?= $this->render('event/events', ['events' => $data]) ?>
<?php endif ?>
