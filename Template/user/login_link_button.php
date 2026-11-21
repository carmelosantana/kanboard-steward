<?php if (! empty($steward_can_mint_link)): ?>
    <li class="steward-sidebar-form">
        <form method="post" action="<?= $this->url->href('StewardLoginLinkController', 'create', ['plugin' => 'Steward', 'user_id' => $user['id']]) ?>">
            <?= $this->form->csrf() ?>
            <?= $this->form->hidden('user_id', ['user_id' => $user['id']]) ?>
            <button type="submit" class="steward-link-button"><?= t('Sign-in link') ?></button>
        </form>
    </li>
<?php endif ?>
