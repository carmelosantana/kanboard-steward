<section id="main">
    <?= $this->projectHeader->render($project, 'StewardProjectHomeController', 'show', false, 'Steward') ?>
    <div class="steward-home">
        <?php foreach ($panels as $name => $data): ?>
            <section class="steward-home-panel steward-home-panel-<?= $this->text->e($name) ?>" data-steward-panel="<?= $this->text->e($name) ?>">
                <?= $this->render('Steward:project_home/panel_'.$name, ['project' => $project, 'settings' => $settings, 'data' => $data]) ?>
            </section>
        <?php endforeach ?>
    </div>
</section>
