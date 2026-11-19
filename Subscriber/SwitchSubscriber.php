<?php
namespace Kanboard\Plugin\Steward\Subscriber;

use Kanboard\Core\Base;
use Kanboard\Core\Security\AuthenticationManager;
use Kanboard\Core\Session\SessionManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ends a switch that core ended (spec §2, "Logout while switched"): core's logout while switched records
 * `switch.end` (reason `logout`), and a real sign-in clears any leftover `steward_switch` (reason `login`).
 */
class SwitchSubscriber extends Base implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SessionManager::EVENT_DESTROY => 'onLogout',
            AuthenticationManager::EVENT_SUCCESS => 'onLogin',
        ];
    }

    public function onLogout(): void
    {
        $this->container['stewardSwitching']->endedByCore('logout');
    }

    public function onLogin(): void
    {
        $this->container['stewardSwitching']->endedByCore('login');
    }
}
