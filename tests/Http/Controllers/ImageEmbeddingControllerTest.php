<?php

namespace Biigle\Tests\Modules\MagicSam\Http\Controllers;

use Mockery;
use ApiTestCase;
use Biigle\Image;
use Illuminate\Http\File;
use Mockery\MockInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Biigle\Modules\MagicSam\Jobs\GenerateEmbedding;

class ImageEmbeddingControllerTest extends ApiTestCase
{
    public function testStore()
    {

        config(['magic_sam.request_queue' => 'gpu-quick']);
        Queue::fake();
        $image = Image::factory()->create(['volume_id' => $this->volume()->id]);

        $this->doTestApiRoute('POST', "/api/v1/images/{$image->id}/sam-embedding");

        $this->beGlobalGuest();
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding")->assertStatus(403);

        $this->beUser();
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding")->assertStatus(403);

        // TODO: test sync and async job processing
        $this->markTestIncomplete();

        // $this->beGuest();
        // $this->postJson("/api/v1/images/{$image->id}/sam-embedding")
        //     ->assertStatus(200)
        //     ->assertJsonStructure(['embedding']);

        // Queue::assertPushedOn('gpu-quick', function (GenerateEmbedding $job) use ($image) {
        //     $this->assertEquals($image->id, $job->image->id);
        //     $this->assertEquals($this->guest()->id, $job->user->id);

        //     return true;
        // });
    }

    public function testStoreExists()
    {
        Queue::fake();
        $image = Image::factory()->create(['volume_id' => $this->volume()->id]);

        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);
        $disk->put("{$image->id}.npy", 'abc');

        // TODO: update test
        $this->markTestIncomplete();

        // $this->beGuest();
        // $this->postJson("/api/v1/images/{$image->id}/sam-embedding")
        //     ->assertStatus(200)
        //     ->assertJsonPath('url', fn ($url) => !is_null($url));
        // Queue::assertNothingPushed();
    }
}