<div class="page-header">
    <h2><?= t('Announcement banner') ?></h2>
</div>

<form method="post" action="<?= $this->url->href('StewardBannerController', 'save', ['plugin' => 'Steward']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <fieldset>
        <legend><?= t('Message') ?></legend>
        <?= $this->form->label(t('Message'), 'steward_banner_message') ?>
        <?= $this->form->textarea('steward_banner_message', $values, [], ['maxlength="'.(int) $max_length.'"', 'rows="3"']) ?>
        <p class="form-help"><?= t('One line, up to %d characters. Allowed: *emphasis*, **strong**, `code` and [links](https://example.com) to http or https addresses; anything else shows as typed. Leave empty to show no banner.', (int) $max_length) ?></p>

        <?= $this->form->label(t('Level'), 'steward_banner_level') ?>
        <?= $this->form->select('steward_banner_level', $levels, $values) ?>
    </fieldset>

    <fieldset>
        <legend><?= t('Schedule') ?></legend>
        <?= $this->form->datetime(t('Start'), 'steward_banner_start', $values) ?>
        <?= $this->form->datetime(t('End'), 'steward_banner_end', $values) ?>
        <p class="form-help"><?= t('In your timezone. Leave empty to start now or to never end.') ?></p>
    </fieldset>

    <fieldset>
        <legend><?= t('Where it shows') ?></legend>
        <p class="form-help"><?= t('Always on signed-in pages.') ?></p>
        <?php /* Unchecked boxes are not submitted: the hidden 0 comes first so a checked box (later) wins. */ ?>
        <input type="hidden" name="steward_banner_login" value="0">
        <?= $this->form->checkbox('steward_banner_login', t('Also on the login page'), 1, $values['steward_banner_login'] === '1') ?>
        <input type="hidden" name="steward_banner_public" value="0">
        <?= $this->form->checkbox('steward_banner_public', t('Also on Steward\'s public project pages and directory'), 1, $values['steward_banner_public'] === '1') ?>
        <input type="hidden" name="steward_banner_dismissible" value="0">
        <?= $this->form->checkbox('steward_banner_dismissible', t('Signed-in users may dismiss it (it shows again when you edit the message, level or times)'), 1, $values['steward_banner_dismissible'] === '1') ?>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>
