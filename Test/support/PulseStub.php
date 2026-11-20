<?php
// The switching tests check Steward's built-in Pulse rows. The harness mounts the real Pulse plugin; CI
// checks out Steward alone, so a stand-in with the same controller and actions is declared only when the
// real class can't be loaded.

namespace Kanboard\Plugin\Pulse\Controller;

if (! class_exists(PulseController::class)) {
    class PulseController
    {
        public function index(): void
        {
        }

        public function project(): void
        {
        }
    }
}
