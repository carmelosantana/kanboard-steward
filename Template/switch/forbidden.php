<!DOCTYPE html>
<html lang="<?= $this->app->jsLang() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width">
    <title><?= t('Not while switched') ?></title>
    <?= $this->asset->css('assets/css/vendor.min.css') ?>
    <?= $this->asset->css('assets/css/'.$this->user->getTheme().'.min.css') ?>
</head>
<body>
<section id="main">
    <div class="page-header">
        <h2><?= t('Not while switched') ?></h2>
    </div>
    <p class="alert alert-error">
        <?= t('This page is not available while you are viewing Kanboard as %s.', $target) ?>
    </p>
    <form method="post" action="<?= $this->url->href('StewardSwitchController', 'back', ['plugin' => 'Steward']) ?>">
        <?= $this->form->csrf() ?>
        <button type="submit" class="btn btn-blue"><?= t('Switch back') ?></button>
        <?= $this->url->link(t('Dashboard'), 'DashboardController', 'show') ?>
    </form>
</section>
</body>
</html>
