<?php if ($this->user->hasProjectAccess('StewardProjectSettingsController', 'show', $project['id'])): ?>
<li <?= $this->app->checkMenuSelection('StewardProjectSettingsController', '', 'Steward') ?>>
    <?= $this->url->link(t('Project page'), 'StewardProjectSettingsController', 'show', ['plugin' => 'Steward', 'project_id' => $project['id']]) ?>
</li>
<?php endif ?>
