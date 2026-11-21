<?php
// Core's Base keeps $this->dispatcher after tearDown, and PHPUnit keeps every finished test object until the run
// ends. Steward's subscribers on that dispatcher hold the whole container, so each test would pin its container
// (~300 KB) and the full suite outgrows PHP's stock 128M memory_limit. Dropping the reference lets it be freed.

require_once 'tests/units/Base.php';

abstract class StewardTestCase extends \KanboardTests\units\Base
{
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->dispatcher = null;
    }
}
