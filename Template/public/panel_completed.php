<h2><?= t('Recently completed') ?></h2>
<?php if (empty($data)): ?>
    <?= $this->render('Steward:public/empty') ?>
<?php else: ?>
    <ul class="steward-public-completed">
        <?php foreach ($data as $task): ?>
            <li><?= $this->text->e($task['title']) ?> <span class="steward-public-date"><?= $this->dt->date($task['date_completed']) ?></span></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
