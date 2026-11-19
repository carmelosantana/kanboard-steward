<?php
/*
 * The form the "Send test email" button submits (template:layout:bottom, outside every page form). Only the CSRF
 * token is posted; the action reads nothing else. $steward_test_email_redirect: null (not this page), '' or 'email'.
 */
if (($steward_test_email_redirect ?? null) === null) {
    return;
}
$params = ['plugin' => 'Steward'];
if ($steward_test_email_redirect === 'email') {
    $params['redirect'] = 'email';
}
?>
<form id="steward-test-email-form" method="post" action="<?= $this->url->href('StewardTestEmailController', 'send', $params) ?>" hidden>
    <?= $this->form->csrf() ?>
</form>
