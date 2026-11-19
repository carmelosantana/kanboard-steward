<h2><?= t('Board') ?></h2>
<?php if (array_sum(array_map(fn ($c) => count($c['tasks']), $data)) === 0): ?>
    <?= $this->render('Steward:public/empty') ?>
<?php else: ?>
    <div class="steward-public-board">
        <?php foreach ($data as $column): ?>
            <div class="steward-public-board-column">
                <h3><?= $this->text->e($column['title']) ?> <span class="steward-public-count">(<?= count($column['tasks']) ?>)</span></h3>
                <ul>
                    <?php foreach ($column['tasks'] as $task): ?>
                        <li><?= $this->text->e($task['title']) ?></li>
                    <?php endforeach ?>
                </ul>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>
