<?php
/**
 * A full-width banner. $level: info|warning|error|success. $message_html: trusted HTML (escape what goes in).
 * $form: null, or ['action' => an href (already escaped, as url->href returns it), 'label' => plain text]: a POST form with CSRF.
 */
$level = in_array($level ?? null, ['info', 'warning', 'error', 'success'], true) ? $level : 'info';
?>
<div class="steward-banner steward-banner-<?= $level ?>" role="<?= $level === 'error' ? 'alert' : 'status' ?>">
    <span class="steward-banner-message"><?= $message_html ?></span>
    <?php if (! empty($form)): ?>
        <form method="post" action="<?= $this->text->e($form['action']) ?>" class="steward-banner-form">
            <?= $this->form->csrf() ?>
            <button type="submit" class="btn"><?= $this->text->e($form['label']) ?></button>
        </form>
    <?php endif ?>
</div>
