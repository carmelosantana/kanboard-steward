<?php
/*
 * "Send test email". Rendered inside another form (core's email settings, Steward's settings), so the button
 * belongs to Steward:config/test_email_form (rendered at the page bottom) through its form attribute: no nested
 * form, and pressing Enter in the outer form still submits that form's own Save button.
 * $steward_test_email_to: the admin's profile email.
 */
$to = (string) ($steward_test_email_to ?? '');
?>
<fieldset class="steward-test-email">
    <legend><?= t('Test email') ?></legend>
    <?php if ($to === ''): ?>
        <button type="button" class="btn" disabled><?= t('Send test email') ?></button>
        <p class="form-help"><?= t('Add an email address to your profile first') ?></p>
    <?php else: ?>
        <button type="submit" class="btn" form="steward-test-email-form"><?= t('Send test email') ?></button>
        <p class="form-help"><?= t('Sends a short message to %s through the active email transport.', $this->text->e($to)) ?></p>
    <?php endif ?>
</fieldset>
