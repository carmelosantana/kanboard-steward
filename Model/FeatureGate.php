<?php
namespace Kanboard\Plugin\Steward\Model;

use Kanboard\Core\Base;

/**
 * Rejects requests for a disabled feature's controllers on `app.bootstrap`, before authentication and ACL
 * middleware run, with one fixed 404 for every viewer. Kanboard dispatches any controller named in the query
 * string, so the routes a feature wires up are not the only way in.
 */
class FeatureGate extends Base
{
    /** Map value for controllers that exist whatever the switches say. */
    public const ALWAYS = 'always';

    /**
     * 'Controller::action' (or 'Controller::*') => feature from SettingsSchema::FEATURES, or ALWAYS.
     * Every public action of every Steward controller needs a row; FeatureGateTest enforces it.
     */
    public const MAP = [
        'StewardSettingsController::*' => self::ALWAYS,
    ];

    /** Static and viewer-independent on purpose: it must not tell anyone which feature, or that it exists. */
    public const NOT_FOUND_BODY = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Not Found</title></head>'
        .'<body><h1>Not Found</h1></body></html>';

    /** `app.bootstrap` listener. No-op without a router context (API, queue workers, CLI). */
    public function onBootstrap(): void
    {
        $plugin = $this->router->getPlugin();
        if ($plugin === '' || $this->check($plugin, $this->router->getController(), $this->router->getAction())) {
            return;
        }

        // BootstrapMiddleware sends these after the event; this response never gets there.
        $this->response->withContentSecurityPolicy($this->container['cspRules']);
        $this->response->withSecurityHeaders();
        $this->response->html(self::NOT_FOUND_BODY, 404);
        $this->halt();
    }

    /** True when the request may proceed. Unmapped Steward actions fail closed. */
    public function check(string $plugin, string $controller, string $action): bool
    {
        if (strcasecmp($plugin, 'Steward') !== 0) {
            return true;
        }
        $feature = $this->featureFor($controller, $action);
        if ($feature === null) {
            return false;
        }
        return $feature === self::ALWAYS || $this->container['stewardFeatures']->isEnabled($feature);
    }

    /** Case-insensitive, like PHP method dispatch and Kanboard's ACL. */
    public function featureFor(string $controller, string $action): ?string
    {
        $rows = array_change_key_case($this->map(), CASE_LOWER);
        foreach ([$controller.'::'.$action, $controller.'::*'] as $key) {
            $key = strtolower($key);
            if (isset($rows[$key])) {
                return $rows[$key];
            }
        }
        return null;
    }

    /** @return array<string,string> */
    protected function map(): array
    {
        return self::MAP;
    }

    /**
     * Stops the request after the 404 is sent. Kanboard's own PageNotFoundException is not used: the Runner
     * renders it with HTTP 200 inside the viewer's layout, so it is neither a 404 nor identical for everyone.
     */
    protected function halt(): never
    {
        exit;
    }
}
