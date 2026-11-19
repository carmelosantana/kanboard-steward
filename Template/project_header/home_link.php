<li <?= $this->app->checkMenuSelection('StewardProjectHomeController', '', 'Steward') ?>>
    <?= $this->url->icon('home', t('Home'), 'StewardProjectHomeController', 'show', ['plugin' => 'Steward', 'project_id' => $project['id']], false, 'view-home') ?>
</li>
