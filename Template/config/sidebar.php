<li <?= $this->app->checkMenuSelection('StewardSettingsController', 'show', 'Steward') ?>>
    <?= $this->url->link('Steward', 'StewardSettingsController', 'show', ['plugin' => 'Steward']) ?>
</li>
