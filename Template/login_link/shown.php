<?php /* The only time the link is shown: nothing stores it in a readable form. Copy is wired in steward.js. */ ?>
<div class="page-header">
    <h2><?= t('Sign-in link') ?></h2>
</div>

<div class="steward-login-link">
    <p><?= sprintf(t('Opening this link signs the browser in as %s.', '%s'), '<strong>'.$this->text->e($this->user->getFullname($steward_link_user)).'</strong>') ?></p>
    <div class="steward-login-link-field">
        <input type="text" id="steward-login-link-url" readonly value="<?= $this->text->e($steward_link_url) ?>" aria-label="<?= t('Sign-in link') ?>" autocomplete="off" spellcheck="false">
        <button type="button" class="btn btn-blue" data-steward-copy="steward-login-link-url" data-steward-copied="<?= t('Copied') ?>"><?= t('Copy') ?></button>
    </div>
    <p><?= t('Works once, until %s.', $this->dt->time($steward_link_expires_at)) ?></p>
    <p class="form-help"><?= t('Creating a new link cancels this one.') ?></p>
    <p><?= $this->url->link(t('Back to the profile'), 'UserViewController', 'show', ['user_id' => $steward_link_user['id'] ?? 0]) ?></p>
</div>
