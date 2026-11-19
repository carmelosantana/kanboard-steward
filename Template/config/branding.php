<div class="page-header">
    <h2><?= t('Branding') ?></h2>
</div>

<form method="post" enctype="multipart/form-data" action="<?= $this->url->href('StewardBrandingController', 'save', ['plugin' => 'Steward']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <fieldset>
        <legend><?= t('Site name') ?></legend>
        <?= $this->form->label(t('Site name'), 'steward_site_name') ?>
        <?= $this->form->text('steward_site_name', $values, [], ['maxlength="'.(int) $max_length.'"']) ?>
        <p class="form-help"><?= t('Plain text, up to %d characters. Leave empty to keep "Kanboard".', (int) $max_length) ?></p>
    </fieldset>

    <fieldset>
        <legend><?= t('Logo') ?></legend>
        <?php if ($logo_url !== ''): ?>
            <p><img src="<?= $this->text->e($logo_url) ?>" alt="<?= t('Current logo') ?>" class="steward-branding-preview"></p>
            <?= $this->form->checkbox('remove_logo', t('Remove the logo'), 1) ?>
        <?php endif ?>
        <?= $this->form->label(t('Upload a logo'), 'logo') ?>
        <?= $this->form->file('logo') ?>
        <p class="form-help"><?= t('PNG, JPEG, WebP or GIF, up to 2 MB. Scaled to fit 512×128.') ?></p>
    </fieldset>

    <fieldset>
        <legend><?= t('Favicon') ?></legend>
        <?php if ($favicon_url !== ''): ?>
            <p><img src="<?= $this->text->e($favicon_url) ?>" alt="<?= t('Current favicon') ?>" width="32" height="32"></p>
            <?= $this->form->checkbox('remove_favicon', t('Remove the favicon'), 1) ?>
        <?php endif ?>
        <?= $this->form->label(t('Upload a favicon'), 'favicon') ?>
        <?= $this->form->file('favicon') ?>
        <p class="form-help"><?= t('PNG, JPEG, WebP or GIF, up to 2 MB. Cropped to a square, 32×32 and 180×180. SVG and ICO are not accepted.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>
