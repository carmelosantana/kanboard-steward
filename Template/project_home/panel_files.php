<div class="page-header"><h2><?= t('Files') ?></h2></div>
<?php if (empty($data)): ?>
    <?= $this->render('Steward:project_home/empty') ?>
<?php else: ?>
    <table class="table-striped table-scrolling">
        <tr>
            <th><?= t('Filename') ?></th>
            <th><?= t('Creator') ?></th>
            <th><?= t('Date') ?></th>
            <th><?= t('Size') ?></th>
        </tr>
        <?php foreach ($data as $file): ?>
            <tr>
                <td>
                    <i class="fa <?= $this->file->icon($file['name']) ?> fa-fw" aria-hidden="true"></i>
                    <?= $this->url->link($this->text->e($file['name']), 'FileViewerController', 'download', ['project_id' => $project['id'], 'file_id' => $file['id'], 'etag' => $file['etag']]) ?>
                    <?php if ($this->file->getPreviewType($file['name']) !== null): ?>
                        <?= $this->modal->large('eye', t('View file'), 'FileViewerController', 'show', ['project_id' => $project['id'], 'file_id' => $file['id'], 'etag' => $file['etag']]) ?>
                    <?php elseif ($this->file->getBrowserViewType($file['name']) !== null): ?>
                        <?= $this->url->icon('eye', t('View file'), 'FileViewerController', 'browser', ['project_id' => $project['id'], 'file_id' => $file['id']], false, '', '', true) ?>
                    <?php endif ?>
                </td>
                <td><?= $this->text->e((string) ($file['user_name'] ?: $file['username'])) ?></td>
                <td><?= $this->dt->date($file['date']) ?></td>
                <td><?= $this->text->bytes($file['size']) ?></td>
            </tr>
        <?php endforeach ?>
    </table>
<?php endif ?>
