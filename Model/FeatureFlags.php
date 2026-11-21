<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;

/** Container key `stewardFeatures`: which Steward features are on. */
class FeatureFlags extends Base
{
    public function isEnabled(string $feature): bool
    {
        if (! in_array($feature, SettingsSchema::FEATURES, true)) {
            return false;
        }
        if ($this->forcedOff($feature)) {
            return false;
        }
        $key = SettingsSchema::featureKey($feature);
        return $this->configModel->get($key, SettingsSchema::defaults()[$key]) === '1';
    }

    /** Features that config.php can turn off, each by its own constant. */
    private const KILL_SWITCHES = [
        'switching' => 'STEWARD_DISABLE_SWITCHING',
        'login_links' => 'STEWARD_DISABLE_LOGIN_LINKS',
    ];

    /** Ops kill switch in config.php, or reverse-proxy auth (the proxy, not Kanboard, owns identity). */
    public function forcedOff(string $feature): bool
    {
        $constant = self::KILL_SWITCHES[$feature] ?? null;
        if ($constant === null) {
            return false;
        }
        return (defined($constant) && constant($constant))
            || (defined('REVERSE_PROXY_AUTH') && REVERSE_PROXY_AUTH);
    }

    public function switchingForcedOff(): bool
    {
        return $this->forcedOff('switching');
    }
}
