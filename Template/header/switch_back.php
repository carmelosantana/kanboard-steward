<?php if (! empty($steward_switched)): ?>
    <li class="steward-dropdown-item">
        <form method="post" action="<?= $this->url->href('StewardSwitchController', 'back', ['plugin' => 'Steward']) ?>">
            <?= $this->form->csrf() ?>
            <button type="submit" class="steward-link-button"><i class="fa fa-fw fa-user-secret" aria-hidden="true"></i><?= t('Switch back') ?></button>
        </form>
    </li>
<?php endif ?>
