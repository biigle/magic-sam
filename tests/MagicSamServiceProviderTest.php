<?php

namespace Biigle\Tests\MagicSams\MagicSam;

use Biigle\Modules\MagicSam\MagicSamServiceProvider;
use TestCase;

class MagicSamServiceProviderTest extends TestCase
{
    public function testServiceProvider()
    {
        $this->assertTrue(class_exists(MagicSamServiceProvider::class));
    }
}
