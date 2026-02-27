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
}

class GenerateEmbeddingStub extends GenerateEmbedding
{
    public $pythonCalled = false;
    public $throw = false;

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
