<h2><?= t('Recent activity') ?></h2>
<?php if (empty($data)): ?>
    <?= $this->render('Steward:public/empty') ?>
<?php else: ?>
    <ul class="steward-public-activity">
        <?php foreach ($data as $event): ?>
            <?php /* text is HTML built by PublicActivity with every value escaped. */ ?>
            <li><?= $event['text'] ?> <span class="steward-public-date"><?= $this->dt->datetime($event['at']) ?></span></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
