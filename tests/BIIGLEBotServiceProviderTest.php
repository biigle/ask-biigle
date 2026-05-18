<?php

namespace Biigle\Tests\Modules\Module;

use Biigle\Modules\BIIGLEBot\BIIGLEBotServiceProvider;
use TestCase;

class BIIGLEBotServiceProviderTest extends TestCase
{
    public function testServiceProvider()
    {
        $this->assertTrue(class_exists(BIIGLEBotServiceProvider::class));
    }
}
