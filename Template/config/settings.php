<?php
$labels = [
    'markdown' => t('Markdown'),
    'switching' => t('User switching'),
    'branding' => t('Branding'),
    'project_pages' => t('Project pages'),
    'public_pages' => t('Public pages'),
    'explore' => t('Explore'),
    'test_email' => t('Test email'),
    'banner' => t('Announcement banner'),
];
?>
<div class="page-header">
    <h2>Steward</h2>
</div>

<form method="post" action="<?= $this->url->href('StewardSettingsController', 'save', ['plugin' => 'Steward']) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>

    <?php foreach ($features as $feature): $key = 'steward_'.$feature ?>
    <fieldset class="steward-feature" id="steward-feature-<?= $this->text->e($feature) ?>">
        <legend><?= $labels[$feature] ?? $this->text->e($feature) ?></legend>
        <?php /* Unchecked boxes are not submitted: the hidden 0 comes first so a checked box (later) wins. */ ?>
        <input type="hidden" name="<?= $key ?>" value="0">
        <?= $this->form->checkbox($key, t('Enabled'), 1, $values[$key] === '1') ?>

        <?php if ($feature === 'switching'): ?>
            <?php if ($switching_forced_off): ?>
                <p class="alert"><?= t('Switching is disabled by STEWARD_DISABLE_SWITCHING or reverse-proxy authentication, whatever this switch says.') ?></p>
            <?php endif ?>
            <?= $this->form->label(t('Idle timeout (minutes)'), 'steward_switch_idle_minutes') ?>
            <?= $this->form->number('steward_switch_idle_minutes', $values, [], ['min="5"', 'max="480"']) ?>
            <p class="form-help"><?= t('Between 5 and 480.') ?></p>
        <?php endif ?>
    </fieldset>
    <?php endforeach ?>

    <fieldset>
        <legend><?= t('Audit log') ?></legend>
        <?= $this->form->label(t('Retention (days)'), 'steward_audit_retention_days') ?>
        <?= $this->form->number('steward_audit_retention_days', $values, [], ['min="7"', 'max="3650"']) ?>
        <p class="form-help"><?= t('Between 7 and 3650.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>
