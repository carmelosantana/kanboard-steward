<?php $stewardIcon32 = $this->stewardBranding->faviconUrl(32) ?>
<?php if ($stewardIcon32 !== ''): ?>
<link rel="icon" type="image/png" sizes="32x32" href="<?= $this->text->e($stewardIcon32) ?>">
<?php endif ?>
<?php $stewardIcon180 = $this->stewardBranding->faviconUrl(180) ?>
<?php if ($stewardIcon180 !== ''): ?>
<link rel="apple-touch-icon" sizes="180x180" href="<?= $this->text->e($stewardIcon180) ?>">
<?php endif ?>
