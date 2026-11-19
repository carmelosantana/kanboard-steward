<?php
/*
 * Public project page (spec §5.3) inside core's layout with no_layout: no app header, no jQuery. Templates here
 * get only the public-safe panel data and the project name; nothing links into the app or to core's token pages.
 */
?>
<?= $this->render('Steward:banner/announcement', ['steward_announcement' => $steward_announcement ?? null]) ?>
<main class="steward-public">
    <?= $this->render('Steward:public/brand') ?>
    <h1 class="steward-public-title"><?= $this->text->e($project['name']) ?></h1>
    <?php foreach ($panels as $name => $data): ?>
        <section class="steward-public-panel steward-public-panel-<?= $this->text->e($name) ?>" data-steward-panel="<?= $this->text->e($name) ?>">
            <?= $this->render('Steward:public/panel_'.$name, ['settings' => $settings, 'data' => $data]) ?>
        </section>
    <?php endforeach ?>
</main>
