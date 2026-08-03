<?php

namespace Biigle\Tests\Modules\askBIIGLE;

use Biigle\Modules\AskBiigle\AskBiigleServiceProvider;
use TestCase;

class AskBiigleServiceProviderTest extends TestCase
{
    public function testServiceProvider()
    {
        $this->assertTrue(class_exists(AskBiigleServiceProvider::class));
    }
}
