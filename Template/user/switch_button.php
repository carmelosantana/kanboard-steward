<?php if (! empty($steward_can_switch)): ?>
    <li>
        <?= $this->modal->confirm('user-secret', t('Switch to this user'), 'StewardSwitchController', 'confirm', ['plugin' => 'Steward', 'user_id' => $user['id']]) ?>
    </li>
<?php endif ?>
