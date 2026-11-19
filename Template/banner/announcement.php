<?php
/*
 * The announcement banner (spec §7), through the shared banner partial. $steward_announcement: Banner::forViewer()
 * or null. Its html is InlineMarkdown output only. A Dismiss form only when forViewer() says so (signed-in layout).
 * $steward_return_to: where Dismiss goes back to (the controller accepts a local path only).
 */
?>
<?php if (! empty($steward_announcement)): ?>
    <?= $this->render('Steward:switch/banner', [
        'level' => $steward_announcement['level'],
        'message_html' => $steward_announcement['html'],
        'form' => $steward_announcement['dismissible'] ? [
            'action' => $this->url->href('StewardBannerController', 'dismiss', ['plugin' => 'Steward']),
            'label' => t('Dismiss'),
            'fields' => ['version' => $steward_announcement['version'], 'redirect' => (string) ($steward_return_to ?? '')],
        ] : null,
    ]) ?>
<?php endif ?>
