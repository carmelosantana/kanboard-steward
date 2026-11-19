<li <?= $this->app->checkMenuSelection('StewardSettingsController', 'show', 'Steward') ?>>
    <?= $this->url->link('Steward', 'StewardSettingsController', 'show', ['plugin' => 'Steward']) ?>
</li>
<li <?= $this->app->checkMenuSelection('StewardAuditController', 'index', 'Steward') ?>>
    <?= $this->url->link(t('Steward audit log'), 'StewardAuditController', 'index', ['plugin' => 'Steward']) ?>
</li>
<?php if ($this->app->stewardFeatures->isEnabled('branding')): ?>
<li <?= $this->app->checkMenuSelection('StewardBrandingController', 'show', 'Steward') ?>>
    <?= $this->url->link(t('Steward branding'), 'StewardBrandingController', 'show', ['plugin' => 'Steward']) ?>
</li>
<?php endif ?>
