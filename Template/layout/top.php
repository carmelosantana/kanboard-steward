<?php if (! empty($steward_switched)): ?>
    <?php $name = '<strong>'.$this->text->e($this->user->getFullname()).'</strong>' ?>
    <?= $this->render('Steward:switch/banner', [
        'level' => 'warning',
        'message_html' => sprintf(t('You are viewing Kanboard as %s', '%s'), $name)
            .' <span class="steward-banner-note">'.sprintf(t('(actions are recorded as %s and bracketed in the audit log)', '%s'), $name).'</span>',
        'form' => [
            'action' => $this->url->href('StewardSwitchController', 'back', ['plugin' => 'Steward']),
            'label' => t('Switch back'),
        ],
    ]) ?>
<?php endif ?>
