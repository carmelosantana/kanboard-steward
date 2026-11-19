<?php
/** Every value from a row is attacker-controllable (usernames as typed, user agents): all output goes through e(). */
$who = function ($id) use ($users) {
    if ($id === null || $id === '') {
        return '';
    }
    return $users[(int) $id] ?? '#'.(int) $id;
};
?>
<div class="page-header">
    <h2><?= t('Audit log') ?></h2>
</div>

<form method="get" action="?" class="steward-audit-filters">
    <input type="hidden" name="controller" value="StewardAuditController">
    <input type="hidden" name="action" value="index">
    <input type="hidden" name="plugin" value="Steward">

    <?= $this->form->label(t('Event'), 'event') ?>
    <?= $this->form->select('event', ['' => t('All')] + array_combine($events, $events), $form) ?>

    <?= $this->form->label(t('User'), 'user_id') ?>
    <?= $this->form->select('user_id', ['' => t('All')] + $users, $form) ?>

    <?= $this->form->label(t('From'), 'from') ?>
    <?= $this->form->text('from', $form, [], ['placeholder="YYYY-MM-DD"', 'pattern="\d{4}-\d{2}-\d{2}"']) ?>

    <?= $this->form->label(t('To'), 'to') ?>
    <?= $this->form->text('to', $form, [], ['placeholder="YYYY-MM-DD"', 'pattern="\d{4}-\d{2}-\d{2}"']) ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-blue"><?= t('Filter') ?></button>
        <?= $this->url->link(t('Reset'), 'StewardAuditController', 'index', ['plugin' => 'Steward']) ?>
    </div>
</form>

<?php if ($paginator->isEmpty()): ?>
    <p class="alert"><?= t('No audit entries.') ?></p>
<?php else: ?>
    <table class="table-striped table-scrolling steward-audit">
        <tr>
            <th><?= t('Date') ?></th>
            <th><?= t('Event') ?></th>
            <th><?= t('Actor') ?></th>
            <th><?= t('Subject') ?></th>
            <th><?= t('IP address') ?></th>
            <th><?= t('User agent') ?></th>
            <th><?= t('Details') ?></th>
        </tr>
        <?php foreach ($paginator->getCollection() as $row): ?>
        <?php $details = json_decode((string) $row['details'], true) ?>
        <tr class="steward-audit-row">
            <td><?= $this->text->e($this->dt->datetime((int) $row['created_at'])) ?></td>
            <td><?= $this->text->e($row['event']) ?></td>
            <td><?= $this->text->e($who($row['actor_user_id'])) ?></td>
            <td><?= $this->text->e($who($row['subject_user_id'])) ?></td>
            <td><?= $this->text->e($row['ip'] ?? '') ?></td>
            <td><?= $this->text->e($row['user_agent'] ?? '') ?></td>
            <td>
                <?php if (is_array($details)): ?>
                    <?php foreach ($details as $key => $value): ?>
                        <div><strong><?= $this->text->e((string) $key) ?>:</strong>
                        <?= $this->text->e(is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></div>
                    <?php endforeach ?>
                <?php else: ?>
                    <?php /* Truncated at 4 KB, so it may not be valid JSON: show it raw. */ ?>
                    <code><?= $this->text->e((string) $row['details']) ?></code>
                <?php endif ?>
            </td>
        </tr>
        <?php endforeach ?>
    </table>
    <?= $paginator ?>
<?php endif ?>
