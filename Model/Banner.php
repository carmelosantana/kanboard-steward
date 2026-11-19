<?php
namespace Kanboard\Plugin\Steward\Model;

use Closure;
use Kanboard\Core\Base;
use Kanboard\Plugin\Steward\Core\InlineMarkdown;
use Pimple\Container;

/**
 * Container key `stewardBanner`: the announcement banner (spec §7). Settings are SettingsSchema::BANNER keys;
 * a user's dismissal is the banner version in their user_metadata, so an edit shows the banner again.
 */
class Banner extends Base
{
    public const DISMISSED_KEY = 'steward_banner_dismissed';

    /** Where a banner renders: signed-in layout, login page, Steward's public pages. */
    public const APP = 'app';
    public const LOGIN = 'login';
    public const PUBLIC = 'public';

    /** @var Closure():int */
    private Closure $clock;

    public function __construct(Container $container, ?Closure $clock = null)
    {
        parent::__construct($container);
        $this->clock = $clock ?? fn () => time();
    }

    /** @return array<string,string> every SettingsSchema::BANNER key */
    public function settings(): array
    {
        $values = [];
        foreach (SettingsSchema::BANNER as $key => $default) {
            $values[$key] = (string) $this->configModel->get($key, $default);
        }
        return $values;
    }

    /** First 16 hex of sha256(message|level|start|end): any edit of what or when gives a new version. */
    public function version(?array $settings = null): string
    {
        $s = $settings ?? $this->settings();
        return substr(hash('sha256', implode('|', [
            $s['steward_banner_message'], $s['steward_banner_level'], $s['steward_banner_start'], $s['steward_banner_end'],
        ])), 0, 16);
    }

    /**
     * The banner showing now, whoever views it, or null (feature off, no message, outside start/end).
     *
     * @return array{version:string,level:string,html:string,dismissible:bool,login:bool,public:bool}|null
     */
    public function current(): ?array
    {
        if (! $this->container['stewardFeatures']->isEnabled('banner')) {
            return null;
        }
        $s = $this->settings();
        if (trim($s['steward_banner_message']) === '') {
            return null;
        }
        $now = ($this->clock)();
        $start = (int) $s['steward_banner_start'];
        $end = (int) $s['steward_banner_end'];
        if (($start > 0 && $now < $start) || ($end > 0 && $now >= $end)) {
            return null;
        }
        return [
            'version' => $this->version($s),
            'level' => in_array($s['steward_banner_level'], SettingsSchema::BANNER_LEVELS, true) ? $s['steward_banner_level'] : 'info',
            'html' => InlineMarkdown::render($s['steward_banner_message']),
            'dismissible' => $s['steward_banner_dismissible'] === '1',
            'login' => $s['steward_banner_login'] === '1',
            'public' => $s['steward_banner_public'] === '1',
        ];
    }

    /**
     * What $place shows the signed-in viewer (or anonymous), or null. 'dismissible' is true only where a dismiss
     * button may render: the signed-in layout. A user who dismissed this version sees nothing anywhere, as long as
     * the banner is still dismissible.
     *
     * @return array{version:string,level:string,html:string,dismissible:bool,login:bool,public:bool}|null
     */
    public function forViewer(string $place): ?array
    {
        $banner = $this->current();
        if ($banner === null || ($place === self::LOGIN && ! $banner['login']) || ($place === self::PUBLIC && ! $banner['public'])) {
            return null;
        }
        $userId = (int) $this->userSession->getId();
        // A dismissal counts only while the banner is dismissible: made non-dismissible, it shows to everyone.
        if ($banner['dismissible'] && $userId > 0 && $this->isDismissed($userId, $banner['version'])) {
            return null;
        }
        $banner['dismissible'] = $banner['dismissible'] && $place === self::APP && $userId > 0;
        return $banner;
    }

    public function isDismissed(int $userId, string $version): bool
    {
        return hash_equals($version, (string) $this->userMetadataModel->get($userId, self::DISMISSED_KEY, ''));
    }

    /** Stores the dismissal; false (nothing written) unless $version is the dismissible banner showing now. */
    public function dismiss(int $userId, string $version): bool
    {
        $banner = $this->current();
        if ($userId <= 0 || $banner === null || ! $banner['dismissible'] || ! hash_equals($banner['version'], $version)) {
            return false;
        }
        return (bool) $this->userMetadataModel->save($userId, [self::DISMISSED_KEY => $version]);
    }
}
