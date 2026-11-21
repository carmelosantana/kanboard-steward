<?php /* Login-page layout: one message, the same whatever went wrong with the link (or "sign out first"). */ ?>
<div class="form-login steward-login-link-page">

    <?php if ($steward_brand !== null): ?><?= $this->render('Steward:auth/logo', $steward_brand) ?><?php endif ?>

    <p class="alert alert-error"><?= $this->text->e($steward_message) ?></p>
    <p><?= $this->url->link(t('Go to %s', $steward_site_name), 'DashboardController', 'show') ?></p>
</div>
