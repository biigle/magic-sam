<?php

namespace Biigle\Tests\Modules\MagicSam\Listeners;

use Queue;
use Storage;
use TestCase;
use Biigle\Tests\ImageTest;
use Biigle\Events\ImagesDeleted;
use Illuminate\Events\CallQueuedListener;
use Biigle\Modules\MagicSam\Listeners\CleanupImageEmbeddings;

class CleanupImageEmbeddingsTest extends TestCase
{
    public function testHandle()
    {
        Storage::fake('test-tiles');
        config(['magic_sam.embedding_storage_disk' => 'test-tiles']);

        $image = ImageTest::create();
        $prefix = fragment_uuid_path($image->uuid);

        Storage::disk('test-tiles')->put("{$prefix}/test.npy", 'content');
        with(new CleanupImageEmbeddings)->handle(new ImagesDeleted($image->uuid));
        $this->assertFalse(Storage::disk('test-tiles')->exists("{$prefix}"));
    }

    public function testListen()
    {
        $image = ImageTest::create();
        event(new ImagesDeleted($image->uuid));
        Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === CleanupImageEmbeddings::class);
    }
}
