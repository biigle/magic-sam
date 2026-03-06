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
        $this->assertArrayNotHasKey('bbox', $data);
    }

    public function testBroadcastWithBbox()
    {
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);
        $disk->put('1/100_200_1024_1024.npy', 'abc');

        $user = User::factory()->create();
        $bbox = ['x' => 100, 'y' => 200, 'width' => 1024, 'height' => 1024];
        $event = new EmbeddingAvailable('1/100_200_1024_1024.npy', $user, $bbox);

        $data = $event->broadcastWith();

        $this->assertArrayHasKey('url', $data);
        $this->assertNotNull($data['url']);
        $this->assertArrayHasKey('bbox', $data);
        $this->assertEquals($bbox, $data['bbox']);
    }
}
