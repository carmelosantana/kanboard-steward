<?php
namespace Kanboard\Plugin\Steward\Subscriber;

use Kanboard\Core\Base;
use Kanboard\Core\Security\AuthenticationManager;
use Kanboard\Event\AuthFailureEvent;
use Kanboard\Event\AuthSuccessEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Writes `login.success` and `login.failure` audit rows from core's auth events. Core dispatches them outside any
 * DB transaction, so the audit write never joins (or rolls back) someone else's work.
 */
class LoginSubscriber extends Base implements EventSubscriberInterface
{
    public const USERNAME_MAX = 64;

    public static function getSubscribedEvents(): array
    {
        return [
            AuthenticationManager::EVENT_SUCCESS => 'onSuccess',
            AuthenticationManager::EVENT_FAILURE => 'onFailure',
        ];
    }

    public function onSuccess(AuthSuccessEvent $event): void
    {
        // Core initialises the user session before it dispatches the event.
        $userId = (int) $this->userSession->getId();
        $userId = $userId > 0 ? $userId : null;
        $this->container['stewardAudit']->logInternal('login.success', $userId, $userId, [
            'auth_type' => (string) $event->getAuthType(),
        ]);
    }

    public function onFailure(AuthFailureEvent $event): void
    {
        // The username is as typed, so attacker-controlled: cut to 64 characters, never trusted as an identity.
        $typed = (string) $event->getUsername();
        $username = mb_substr(mb_scrub($typed, 'UTF-8'), 0, self::USERNAME_MAX, 'UTF-8');
        $this->container['stewardAudit']->logInternal('login.failure', null, $this->userIdFor($typed), [
            'username' => $username,
        ]);
    }

    private function userIdFor(string $username): ?int
    {
        if ($username === '') {
            return null;
        }
        try {
            $id = (int) $this->userModel->getIdByUsername($username);
            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
