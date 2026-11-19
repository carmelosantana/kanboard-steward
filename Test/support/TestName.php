<?php
// The running test's method name on PHPUnit 9 (Kanboard 1.2.47) and PHPUnit 10+ (Kanboard 1.2.57).

trait StewardTestName
{
    private function currentTestName(): string
    {
        return method_exists($this, 'name') ? $this->name() : $this->getName(false);
    }
}
