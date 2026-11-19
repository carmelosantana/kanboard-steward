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
        if ($feature === 'switching' && $this->switchingForcedOff()) {
            return false;
        }
        $key = SettingsSchema::featureKey($feature);
        return $this->configModel->get($key, SettingsSchema::defaults()[$key]) === '1';
    }

    /** Ops kill switch in config.php, or reverse-proxy auth (the proxy, not Kanboard, owns identity). */
    public function switchingForcedOff(): bool
    {
        return (defined('STEWARD_DISABLE_SWITCHING') && STEWARD_DISABLE_SWITCHING)
            || (defined('REVERSE_PROXY_AUTH') && REVERSE_PROXY_AUTH);
    }
}
