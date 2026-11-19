<?php
/* Project directory (spec §5.4): each listed project's name, tagline and link. Nothing else. */
?>
<main class="steward-public">
    <?= $this->render('Steward:public/brand') ?>
    <h1 class="steward-public-title"><?= t('Explore') ?></h1>
    <?php if (empty($entries)): ?>
        <p class="steward-public-empty"><?= t('Nothing yet.') ?></p>
    <?php else: ?>
        <ul class="steward-explore">
            <?php foreach ($entries as $entry): ?>
                <li class="steward-explore-entry">
                    <a href="<?= $this->text->e($entry['url']) ?>" class="steward-explore-name"><?= $this->text->e($entry['name']) ?></a>
                    <?php if ($entry['tagline'] !== ''): ?>
                        <p class="steward-public-tagline"><?= $this->text->e($entry['tagline']) ?></p>
                    <?php endif ?>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</main>
