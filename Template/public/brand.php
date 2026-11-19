<?php
/* Site logo or name above a Steward public page; nothing while branding is off. */
$stewardLogo = $this->stewardBranding->logoUrl();
$stewardName = $this->stewardBranding->siteName();
?>
<?php if ($stewardLogo !== '' || $stewardName !== ''): ?>
    <div class="steward-public-brand">
        <?php if ($stewardLogo !== ''): ?>
            <img src="<?= $this->text->e($stewardLogo) ?>" alt="<?= $this->text->e($stewardName !== '' ? $stewardName : 'Kanboard') ?>" class="steward-public-logo">
        <?php else: ?>
            <span class="steward-public-site"><?= $this->text->e($stewardName) ?></span>
        <?php endif ?>
    </div>
<?php endif ?>
