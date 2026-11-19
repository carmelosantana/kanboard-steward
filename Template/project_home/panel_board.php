<div class="page-header"><h2><?= t('Board') ?></h2></div>
<?php if (array_sum(array_map(fn ($c) => count($c['tasks']), $data)) === 0): ?>
    <?= $this->render('Steward:project_home/empty') ?>
<?php else: ?>
    <div class="steward-home-board">
        <?php foreach ($data as $column): ?>
            <div class="steward-home-board-column">
                <h3><?= $this->text->e($column['title']) ?> <span class="steward-home-count">(<?= count($column['tasks']) ?>)</span></h3>
                <ul>
                    <?php foreach ($column['tasks'] as $task): ?>
                        <li>
                            <?php if (isset($task['id'])): ?>
                                <?= $this->url->link($this->text->e($task['title']), 'TaskViewController', 'show', ['task_id' => $task['id'], 'project_id' => $project['id']]) ?>
                            <?php else: ?>
                                <?= $this->text->e($task['title']) ?>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>
