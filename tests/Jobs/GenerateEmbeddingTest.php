<?php

namespace Biigle\Tests\Modules\MagicSam\Jobs;

use Biigle\Image;
use Biigle\Modules\MagicSam\Events\EmbeddingAvailable;
use Biigle\Modules\MagicSam\Events\EmbeddingFailed;
use Biigle\Modules\MagicSam\Jobs\GenerateEmbedding;
use Biigle\User;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use TestCase;

class GenerateEmbeddingTest extends TestCase
{
    public function testHandle()
    {
        Event::fake();
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);

        $image = Image::factory()->create();
        $disk->put('files/test-image.jpg', 'abc');
        $user = User::factory()->create();
        $job = new GenerateEmbeddingStub($image, $user);

        $job->handle();
        $this->assertEquals('response', $disk->get("{$image->id}.npy"));
        $this->assertTrue($job->pythonCalled);

        Event::assertDispatched(function (EmbeddingAvailable $event) use ($user, $image) {
            $this->assertEquals($user->id, $event->user->id);
            $this->assertEquals("{$image->id}.npy", $event->filename);

            return true;
        });
    }

    public function testHandleExists()
    {
        Event::fake();
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);

        $image = Image::factory()->create();
        $disk->put('files/test-image.jpg', 'abc');
        $disk->put("{$image->id}.npy", 'abc');
        $user = User::factory()->create();
        $job = new GenerateEmbeddingStub($image, $user);

        $job->handle();
        $this->assertFalse($job->pythonCalled);

        Event::assertDispatched(function (EmbeddingAvailable $event) use ($user, $image) {
            $this->assertEquals($user->id, $event->user->id);
            $this->assertEquals("{$image->id}.npy", $event->filename);

            return true;
        });
    }

    public function testHandleException()
    {
        Event::fake();
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);

        $image = Image::factory()->create();
        $disk->put('files/test-image.jpg', 'abc');
        $user = User::factory()->create();
        $job = new GenerateEmbeddingStub($image, $user);
        $job->throw = true;

        try {
            $job->handle();
            $this->fail('Expected an exception');
        } catch (Exception $e) {
            $this->assertEquals('', $e->getMessage());
        }

        Event::assertDispatched(function (EmbeddingFailed $event) use ($user) {
            $this->assertEquals($user->id, $event->user->id);

            return true;
        });
    }

    public function testHandleDecrementsCounter()
    {
        Event::fake();
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);

        $image = Image::factory()->create();
        $disk->put('files/test-image.jpg', 'abc');
        $user = User::factory()->create();
        $job = new GenerateEmbeddingStub($image, $user);

        $cacheKey = GenerateEmbedding::getPendingJobsCacheKey($user);
        Cache::put($cacheKey, 3);

        $job->handle();

        $this->assertEquals(2, Cache::get($cacheKey));
    }

    public function testHandleDecrementsCounterOnException()
    {
        Event::fake();
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);

        $image = Image::factory()->create();
        $disk->put('files/test-image.jpg', 'abc');
        $user = User::factory()->create();
        $job = new GenerateEmbeddingStub($image, $user);
        $job->throw = true;

        $cacheKey = GenerateEmbedding::getPendingJobsCacheKey($user);
        Cache::put($cacheKey, 3);

        try {
            $job->handle();
            $this->fail('Expected an exception');
        } catch (Exception $e) {
            // Expected
        }

        $this->assertEquals(2, Cache::get($cacheKey));
    }

    public function testHandleDeletesCacheKeyWhenZero()
    {
        Event::fake();
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);

        $image = Image::factory()->create();
        $disk->put('files/test-image.jpg', 'abc');
        $user = User::factory()->create();
        $job = new GenerateEmbeddingStub($image, $user);

        $cacheKey = GenerateEmbedding::getPendingJobsCacheKey($user);
        Cache::put($cacheKey, 1);

        $job->handle();

        $this->assertFalse(Cache::has($cacheKey));
    }

    public function testHandleWithBbox()
    {
        Event::fake();
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);

        $image = Image::factory()->create();
        $disk->put('files/test-image.jpg', 'abc');
        $user = User::factory()->create();

        $bbox = ['x' => 100, 'y' => 200, 'width' => 1024, 'height' => 1024];
        $job = new GenerateEmbeddingStub($image, $user, $bbox);

        $job->handle();

        $expectedFilename = "{$image->id}/100_200_1024_1024.npy";
        $this->assertEquals('response', $disk->get($expectedFilename));
        $this->assertTrue($job->pythonCalled);

        Event::assertDispatched(function (EmbeddingAvailable $event) use ($user, $image, $bbox) {
            $this->assertEquals($user->id, $event->user->id);
            $this->assertEquals("{$image->id}/100_200_1024_1024.npy", $event->filename);
            $this->assertEquals($bbox, $event->bbox);

            return true;
        });
    }

    public function testHandleWithBboxExists()
    {
        Event::fake();
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);

        $image = Image::factory()->create();
        $disk->put('files/test-image.jpg', 'abc');
        $disk->put("{$image->id}/100_200_1024_1024.npy", 'existing');
        $user = User::factory()->create();

        $bbox = ['x' => 100, 'y' => 200, 'width' => 1024, 'height' => 1024];
        $job = new GenerateEmbeddingStub($image, $user, $bbox);

        $job->handle();
        $this->assertFalse($job->pythonCalled);

        Event::assertDispatched(function (EmbeddingAvailable $event) use ($bbox) {
            $this->assertEquals($bbox, $event->bbox);

            return true;
        });
    }

    public function testPrepareTileLoadingInfo()
    {
        $image = Image::factory()->create([
            'width' => 8192,
            'height' => 8192,
            'tiled' => true,
        ]);
        $user = User::factory()->create();

        $bbox = ['x' => 0, 'y' => 0, 'width' => 4096, 'height' => 4096];
        $job = new GenerateEmbeddingStub($image, $user, $bbox);

        $info = $job->getTileLoadingInfo($bbox, 1024);

        $this->assertEquals(3, $info['zoom_level']);
        $this->assertEquals(0.25, $info['scale']);
        $this->assertEquals(['x' => 0, 'y' => 0, 'width' => 1024, 'height' => 1024], $info['bbox_at_level']);
        $this->assertEquals(0, $info['col_start']);
        $this->assertEquals(3, $info['col_end']);
        $this->assertEquals(0, $info['row_start']);
        $this->assertEquals(3, $info['row_end']);
        $this->assertEquals(16, $info['tile_count']);
        $this->assertEquals(21, $info['tiles_before_level']);
        $this->assertEquals(8, $info['tiles_wide_at_level']);
    }

    public function testGetFilename()
    {
        $image = Image::factory()->create();
        $user = User::factory()->create();

        // Without bbox.
        $job = new GenerateEmbedding($image, $user);
        $this->assertEquals("{$image->id}.npy", $job->getFilename());

        // With bbox.
        $bbox = ['x' => 50, 'y' => 75, 'width' => 512, 'height' => 512];
        $job = new GenerateEmbedding($image, $user, $bbox);
        $this->assertEquals("{$image->id}/50_75_512_512.npy", $job->getFilename());
    }
}

class GenerateEmbeddingStub extends GenerateEmbedding
{
    public $pythonCalled = false;
    public $throw = false;

    public function getTileLoadingInfo(array $bbox, int $modelInputSize): array
    {
        return $this->prepareTileLoadingInfo($bbox, $modelInputSize);
    }

    protected function getImageBufferForPyworker(string $path): string
    {
        return 'buffer';
    }

    protected function sendPyworkerRequest(string $buffer): string
    {
        if ($this->throw) {
            throw new Exception();
        }

        $this->pythonCalled = true;

        return 'response';
    }
}
