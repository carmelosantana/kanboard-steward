<h2><?= t('Description') ?></h2>
<?php if (trim($data) === ''): ?>
    <?= $this->render('Steward:public/empty') ?>
<?php else: ?>
    <?php /* Already HTML: Steward's parser in public mode (escaped markup, safe URLs, #N and @user plain). */ ?>
    <article class="markdown"><?= $data ?></article>
<?php endif ?>
