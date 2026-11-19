<div class="page-header">
    <h2><?= t('Switch to this user') ?></h2>
</div>

<?php if (empty($user)): ?>
    <p class="alert alert-error"><?= $this->text->e($refusal) ?></p>
    <div class="form-actions">
        <a href="#" class="btn js-modal-close"><?= t('Close') ?></a>
    </div>
<?php else: ?>
    <?php $name = $this->user->getFullname($user) ?>
    <form method="post" action="<?= $this->url->href('StewardSwitchController', 'start', ['plugin' => 'Steward']) ?>" autocomplete="off">
        <?= $this->form->csrf() ?>
        <?= $this->form->hidden('user_id', ['user_id' => $user['id']]) ?>
        <div class="confirm">
            <p class="alert alert-info">
                <?= sprintf(t('You are about to view Kanboard as %s.', '%s'), '<strong>'.$this->text->e($name).'</strong>') ?>
            </p>
            <p>
                <?= t('Anything you do is recorded as %s, and Steward brackets it in its audit log as done by you while switched.', $name) ?>
                <?= t('To come back, use Switch back and sign in again.') ?>
            </p>
        </div>
        <?= $this->modal->submitButtons(['submitLabel' => t('Switch to this user')]) ?>
    </form>
<?php endif ?>
