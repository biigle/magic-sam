<?php

namespace Biigle\Tests\Modules\MagicSam\Events;

use Biigle\Modules\MagicSam\Events\EmbeddingAvailable;
use Biigle\User;
use Illuminate\Support\Facades\Storage;
use TestCase;

class EmbeddingAvailableTest extends TestCase
{
    public function testBroadcastWith()
    {
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);
        $disk->put('1.npy', 'abc');

        $user = User::factory()->create();
        $event = new EmbeddingAvailable('1.npy', $user);

        $data = $event->broadcastWith();

        $this->assertArrayHasKey('url', $data);
        $this->assertNotNull($data['url']);
        $this->assertArrayNotHasKey('extent', $data);
    }

    public function testBroadcastWithExtent()
    {
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);
        $disk->put('1/100_200_1024_1024.npy', 'abc');

        $user = User::factory()->create();
        $extent = ['x' => 100, 'y' => 200, 'width' => 1024, 'height' => 1024];
        $event = new EmbeddingAvailable('1/100_200_1024_1024.npy', $user, $extent);

        $data = $event->broadcastWith();

        $this->assertArrayHasKey('url', $data);
        $this->assertNotNull($data['url']);
        $this->assertArrayHasKey('extent', $data);
        $this->assertEquals($extent, $data['extent']);
    }
}
