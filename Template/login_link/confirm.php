<?php /* Login-page layout (no app chrome): the link's user and one button. GET shows this; only the POST signs in. */ ?>
<div class="form-login steward-login-link-page">

    <?= $this->hook->render('template:auth:login-form:before') ?>

    <form method="post" action="<?= $this->url->href('StewardLoginLinkController', 'redeem', ['plugin' => 'Steward', 'token' => $steward_link_token]) ?>">
        <?= $this->form->csrf() ?>
        <p><?= sprintf(t('Sign in to %s as %s', '%s', '%s'), $this->text->e($steward_site_name), '<strong>'.$this->text->e($steward_link_user['username']).'</strong>') ?></p>
        <div class="form-actions">
            <button type="submit" class="btn btn-blue"><?= t('Sign in') ?></button>
        </div>
    </form>

    <?= $this->hook->render('template:auth:login-form:after') ?>
</div>
