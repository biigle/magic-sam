<?php

namespace Biigle\Tests\Modules\MagicSam\Jobs;

use Biigle\Image;
use Biigle\Modules\MagicSam\Events\EmbeddingAvailable;
use Biigle\Modules\MagicSam\Events\EmbeddingFailed;
use Biigle\Modules\MagicSam\Jobs\GenerateEmbedding;
use Biigle\User;
use Exception;
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
        $outputFile = sys_get_temp_dir()."/{$image->id}.npy";
        $job = new GenerateEmbeddingStub($image, $user);

        try {
            File::put($outputFile, 'abc');
            $job->handle();
            $disk->assertExists("{$image->id}.npy");
        } finally {
            File::delete($outputFile);
        }

        Event::assertDispatched(function (EmbeddingAvailable $event) use ($user, $image) {
            $this->assertEquals($user->id, $event->user->id);
            $this->assertEquals("{$image->id}.npy", $event->filename);

            return true;
        });
    }

    public function testHandleSync()
    {
        Event::fake();
        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);

        $image = Image::factory()->create();
        $disk->put('files/test-image.jpg', 'abc');
        $user = User::factory()->create();
        $filename = "{$image->id}.npy";
        $outputFile = sys_get_temp_dir()."/{$filename}";
        $job = new GenerateEmbeddingStub($image, $user, False);

        try {
            File::put($outputFile, 'abc');
            $job->handle();
            $disk->assertExists("{$image->id}.npy");
        } finally {
            File::delete($outputFile);
        }

        Event::assertNotDispatched(EmbeddingAvailable::class);
        Event::assertNotDispatched(EmbeddingFailed::class);
        $this->assertTrue($disk->exists($filename));
        $this->assertGreaterThan(0, $disk->size($filename));
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
}

class GenerateEmbeddingStub extends GenerateEmbedding
{

    public $throw = false;

    protected function generateEmbedding($outputPath)
    {
        if ($this->throw) {
            throw new Exception('');
        }

        return "test";
    }
}
