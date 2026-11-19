<?php
$panelLabels = [
    'header' => t('Header (name, tagline, links)'),
    'description' => t('Description'),
    'progress' => t('Progress'),
    'board' => t('Board snapshot'),
    'activity' => t('Recent activity'),
    'completed' => t('Recently completed'),
    'files' => t('Files'),
];
// Ticked panels first in their order, then the rest in the default order; the position column follows the rows.
$rows = array_merge($values['panels'], array_values(array_diff($home_panels, $values['panels'])));
$corePublic = ! empty($project['is_public']);
$fieldError = function (string $field) use ($errors) {
    return isset($errors[$field]) ? '<ul class="form-errors"><li>'.$this->text->e($errors[$field]).'</li></ul>' : '';
};
?>
<div class="page-header">
    <h2><?= t('Project page') ?></h2>
</div>

<?php if ($errors !== []): ?>
    <p class="alert alert-error"><?= t('Nothing was saved. Fix the fields below and save again.') ?></p>
<?php endif ?>

<form method="post" action="<?= $this->url->href('StewardProjectSettingsController', 'save', ['plugin' => 'Steward', 'project_id' => $project['id']]) ?>" autocomplete="off">
    <?= $this->form->csrf() ?>
    <?= $this->form->hidden('project_id', ['project_id' => $project['id']]) ?>
    <?= $fieldError('form') ?>

    <fieldset>
        <legend><?= t('Header') ?></legend>
        <?= $this->form->label(t('Tagline'), 'tagline') ?>
        <?= $this->form->text('tagline', ['tagline' => is_string($values['tagline']) ? $values['tagline'] : ''], [], ['maxlength="'.$tagline_max.'"']) ?>
        <?= $fieldError('tagline') ?>
        <p class="form-help"><?= t('Plain text, one line, up to %d characters.', $tagline_max) ?></p>

        <p><strong><?= t('Links') ?></strong></p>
        <?php for ($i = 0; $i < $links_max; $i++): ?>
            <?php $link = $values['links'][$i] ?? ['label' => '', 'url' => ''] ?>
            <div class="steward-link-row">
                <input type="text" name="link_label[<?= $i ?>]" id="form-link_label_<?= $i ?>" value="<?= $this->text->e(is_string($link['label'] ?? null) ? $link['label'] : '') ?>" maxlength="<?= $label_max ?>" placeholder="<?= t('Label') ?>" aria-label="<?= t('Link %d label', $i + 1) ?>">
                <input type="url" name="link_url[<?= $i ?>]" id="form-link_url_<?= $i ?>" value="<?= $this->text->e(is_string($link['url'] ?? null) ? $link['url'] : '') ?>" placeholder="https://" aria-label="<?= t('Link %d address', $i + 1) ?>">
            </div>
        <?php endfor ?>
        <?= $fieldError('links') ?>
        <p class="form-help"><?= t('Up to %d links, each with a label (up to %d characters) and an http:// or https:// address. Leave a row empty to drop it.', $links_max, $label_max) ?></p>
    </fieldset>

    <fieldset>
        <legend><?= t('Panels') ?></legend>
        <table class="table-small">
            <thead>
                <tr>
                    <th><?= t('Panel') ?></th>
                    <th><?= t('Home') ?></th>
                    <th><?= t('Public page') ?></th>
                    <th><?= t('Position') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $position => $panel): ?>
                <?php $name = $this->text->e($panel) ?>
                <tr>
                    <td><?= $this->text->e($panelLabels[$panel] ?? $panel) ?></td>
                    <td><input type="checkbox" name="panels[<?= $name ?>]" value="1" aria-label="<?= t('Show on Home') ?>" <?= in_array($panel, $values['panels'], true) ? 'checked' : '' ?>></td>
                    <td>
                        <?php if (in_array($panel, $public_panels, true)): ?>
                            <input type="checkbox" name="public_panels[<?= $name ?>]" value="1" aria-label="<?= t('Show on the public page') ?>" <?= in_array($panel, $values['public_panels'], true) ? 'checked' : '' ?>>
                        <?php else: ?>
                            <span class="form-help"><?= t('Never public') ?></span>
                        <?php endif ?>
                    </td>
                    <td><input type="number" name="order[<?= $name ?>]" value="<?= $position + 1 ?>" min="1" max="<?= count($rows) ?>" aria-label="<?= t('Position') ?>"></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        <?= $fieldError('panels') ?>
        <?= $fieldError('public_panels') ?>
        <p class="form-help"><?= t('Panels show in position order, lowest first, on Home and on the public page.') ?></p>
    </fieldset>

    <fieldset>
        <legend><?= t('Public page') ?></legend>
        <?php if ($corePublic): ?>
            <?= $this->form->checkbox('public', t('Public page'), 1, $values['public'] === true) ?>
        <?php else: ?>
            <?= $this->form->checkbox('public', t('Public page'), 1, false, '', ['disabled' => 'disabled']) ?>
            <p class="form-help"><?= t('Turn on this project\'s public access first (Public access in this sidebar).') ?></p>
            <?php if (! empty($public_returns)): ?>
                <p class="alert alert-info"><?= t('The public page will come back if public access is turned on again.') ?></p>
            <?php endif ?>
        <?php endif ?>
        <?= $fieldError('public') ?>
        <p class="alert"><?= t('The public page needs this project\'s public access, which also publishes the read-only board (with assignees), the RSS and iCal feeds and task pages (with comments and subtasks) to anyone who has its link. The public page never shows that link, but whoever already has it sees all of that.') ?></p>

        <?= $this->form->checkbox('listed', t('Listed in the project directory'), 1, $values['listed'] === true) ?>
        <?= $fieldError('listed') ?>
        <p class="form-help"><?= t('Needs the public page and a project identifier.') ?></p>

        <?= $this->form->checkbox('show_names', t('Show names in public activity'), 1, $values['show_names'] === true) ?>
        <?= $fieldError('show_names') ?>
        <p class="form-help"><?= t('Shows each person\'s display name, never a username or email address.') ?></p>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Save') ?></button>
    </div>
</form>
