<div class="page-header"><h2><?= t('Recently completed') ?></h2></div>
<?php if (empty($data)): ?>
    <?= $this->render('Steward:project_home/empty') ?>
<?php else: ?>
    <ul class="steward-home-completed">
        <?php foreach ($data as $task): ?>
            <li>
                <?= $this->url->link($this->text->e($task['title']), 'TaskViewController', 'show', ['task_id' => $task['id'], 'project_id' => $project['id']]) ?>
                <span class="steward-home-date"><?= $this->dt->date($task['date_completed']) ?></span>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
