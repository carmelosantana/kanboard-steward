<li <?= $this->app->checkMenuSelection('StewardSettingsController', 'show', 'Steward') ?>>
    <?= $this->url->link('Steward', 'StewardSettingsController', 'show', ['plugin' => 'Steward']) ?>
</li>
<li <?= $this->app->checkMenuSelection('StewardAuditController', 'index', 'Steward') ?>>
    <?= $this->url->link(t('Steward audit log'), 'StewardAuditController', 'index', ['plugin' => 'Steward']) ?>
</li>
