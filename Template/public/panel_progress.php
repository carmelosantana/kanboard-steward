<h2><?= t('Progress') ?></h2>
<p class="steward-public-progress-total">
    <progress value="<?= (int) $data['closed'] ?>" max="<?= max(1, $data['open'] + $data['closed']) ?>"></progress>
    <?= t('%d%% closed (%d open, %d closed)', $data['percent'], $data['open'], $data['closed']) ?>
</p>
<table class="steward-public-table">
    <tr>
        <th><?= t('Column') ?></th>
        <th><?= t('Open tasks') ?></th>
        <th></th>
    </tr>
    <?php foreach ($data['columns'] as $column): ?>
        <tr>
            <td><?= $this->text->e($column['title']) ?></td>
            <td><?= (int) $column['open'] ?></td>
            <td><progress value="<?= (int) $column['open'] ?>" max="<?= max(1, $data['open']) ?>"></progress></td>
        </tr>
    <?php endforeach ?>
</table>
