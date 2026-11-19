<?php
/*
 * Login page (template:auth:login-form:before): Steward's own logo, else the site name, else nothing.
 * No ShadcnTheme fallback here: ShadcnTheme's own login card already shows its logo.
 */
?>
<?php if ($steward_logo_url !== ''): ?>
<div class="steward-login-brand">
    <img src="<?= $this->text->e($steward_logo_url) ?>" alt="<?= $this->text->e($steward_site_name !== '' ? $steward_site_name : 'Kanboard') ?>" class="steward-login-logo">
</div>
<?php elseif ($steward_site_name !== ''): ?>
<div class="steward-login-brand">
    <h1 class="steward-login-name"><?= $this->text->e($steward_site_name) ?></h1>
</div>
<?php endif ?>
