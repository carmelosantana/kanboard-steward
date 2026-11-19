<?php if ($settings['tagline'] !== ''): ?>
    <p class="steward-public-tagline"><?= $this->text->e($settings['tagline']) ?></p>
<?php endif ?>
<?php if (! empty($settings['links'])): ?>
    <ul class="steward-public-links">
        <?php foreach ($settings['links'] as $link): ?>
            <li><a href="<?= $this->text->e($link['url']) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= $this->text->e($link['label']) ?></a></li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
