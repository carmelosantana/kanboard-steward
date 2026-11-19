<?php
/*
 * Steward override of core header/title (registered only while branding is on). With no logo and no site
 * name it renders core's own file. Otherwise core's markup (Kanboard 1.2.47) with only the .logo link
 * changed: the logo (Steward's, else ShadcnTheme's while that slot is empty, spec §5.6), else the site name.
 */
$stewardLogo = $this->stewardBranding->logoUrl();
$stewardName = $this->stewardBranding->siteName();
if ($stewardLogo === '' && $stewardName === '') {
    include $this->getTemplateFile('kanboard:header/title');
    return;
}
?>
<h1>
    <span class="logo">
        <?php if ($stewardLogo !== ''): ?>
            <?= $this->url->link('<img src="'.$this->text->e($stewardLogo).'" alt="'.$this->text->e($stewardName !== '' ? $stewardName : 'Kanboard').'" class="steward-header-logo">', 'DashboardController', 'show', array(), false, '', t('Dashboard')) ?>
        <?php else: ?>
            <?= $this->url->link($this->text->e($stewardName), 'DashboardController', 'show', array(), false, 'steward-site-name', t('Dashboard')) ?>
        <?php endif ?>
    </span>
    <span class="title">
        <?php if (! empty($project) && ! empty($task)): ?>
            <?= $this->url->link($this->text->e($project['name']), 'BoardViewController', 'show', array('project_id' => $project['id'])) ?>
        <?php else: ?>
            <?= $this->text->e($title) ?>
            <?php if (! empty($project) && $project['task_limit'] && array_key_exists('nb_active_tasks', $project)): ?>
              (<span><?= intval($project['nb_active_tasks']) ?></span> / <span title="<?= t('Task limit') ?>"><span class="ui-helper-hidden-accessible"><?= t('Task limit') ?> </span><?= $this->text->e($project['task_limit']) ?></span>)
            <?php endif ?>
        <?php endif ?>
    </span>
    <?php if (! empty($description)): ?>
        <?= $this->app->tooltipHTML($description) ?>
    <?php endif ?>
</h1>
