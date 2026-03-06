<?php

namespace Biigle\Tests\Modules\MagicSam\Http\Controllers;

use ApiTestCase;
use Biigle\Image;
use Biigle\Modules\MagicSam\Jobs\GenerateEmbedding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class ImageEmbeddingControllerTest extends ApiTestCase
{
    public function testStore()
    {
        config([
            'magic_sam.request_queue' => 'quick',
            'magic_sam.embedding_storage_disk' => 'test',
        ]);
        Queue::fake();
        $image = Image::factory()->create(['volume_id' => $this->volume()->id]);

        $this->doTestApiRoute('POST', "/api/v1/images/{$image->id}/sam-embedding");

        $this->beGlobalGuest();
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding")->assertStatus(403);

        $this->beUser();
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding")->assertStatus(403);

        $this->beGuest();
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding")->assertStatus(403);

        $this->beEditor();
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding")
            ->assertStatus(200)
            ->assertExactJson(['url' => null]);

        Queue::assertPushedOn('quick', function (GenerateEmbedding $job) use ($image) {
            $this->assertEquals($image->id, $job->image->id);
            $this->assertEquals($this->editor()->id, $job->user->id);

            return true;
        });
    }

    public function testStoreExists()
    {
        Queue::fake();
        $image = Image::factory()->create(['volume_id' => $this->volume()->id]);

        $disk = Storage::fake('test');
        config(['magic_sam.embedding_storage_disk' => 'test']);
        $disk->put("{$image->id}.npy", 'abc');

        $this->beEditor();
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding")
            ->assertStatus(200)
            ->assertJsonPath('url', fn ($url) => !is_null($url));
        Queue::assertNothingPushed();
    }

    public function testStoreRateLimit()
    {
        config([
            'magic_sam.embedding_storage_disk' => 'test',
            'magic_sam.max_parallel_jobs_per_user' => 2,
        ]);
        Queue::fake();
        Cache::flush();

        $image1 = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'filename' => 'test-image-1.jpg',
        ]);
        $image2 = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'filename' => 'test-image-2.jpg',
        ]);
        $image3 = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'filename' => 'test-image-3.jpg',
        ]);

        $this->beEditor();

        $this->postJson("/api/v1/images/{$image1->id}/sam-embedding")
            ->assertStatus(200);

        $this->postJson("/api/v1/images/{$image2->id}/sam-embedding")
            ->assertStatus(200);

        $this->postJson("/api/v1/images/{$image3->id}/sam-embedding")
            ->assertStatus(429);

        Queue::assertPushed(GenerateEmbedding::class, 2);
    }

    public function testStoreWithExtent()
    {
        config([
            'magic_sam.request_queue' => 'quick',
            'magic_sam.embedding_storage_disk' => 'test',
            'magic_sam.model_input_size' => 1024,
        ]);
        Queue::fake();

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 2048,
            'height' => 2048,
        ]);

        $this->beEditor();
        $this
            ->postJson("/api/v1/images/{$image->id}/sam-embedding", [
                'x' => 100,
                'y' => 200,
                'width' => 1024,
                'height' => 1024,
            ])
            ->assertStatus(200)
            ->assertJson([
                'url' => null,
                'extent' => [
                    'x' => 100,
                    'y' => 200,
                    'width' => 1024,
                    'height' => 1024,
                ],
            ]);

        Queue::assertPushedOn('quick', function (GenerateEmbedding $job) use ($image) {
            $this->assertEquals($image->id, $job->image->id);
            $this->assertEquals([
                'x' => 100,
                'y' => 200,
                'width' => 1024,
                'height' => 1024,
            ], $job->extent);

            return true;
        });
    }

    public function testStoreWithExtentExists()
    {
        Queue::fake();
        config([
            'magic_sam.embedding_storage_disk' => 'test',
            'magic_sam.model_input_size' => 1024,
        ]);

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 2048,
            'height' => 2048,
        ]);

        $disk = Storage::fake('test');
        $disk->put("{$image->id}/100_200_1024_1024.npy", 'abc');

        $this->beEditor();
        $this
            ->postJson("/api/v1/images/{$image->id}/sam-embedding", [
                'x' => 100,
                'y' => 200,
                'width' => 1024,
                'height' => 1024,
            ])
            ->assertStatus(200)
            ->assertJsonPath('url', fn ($url) => !is_null($url))
            ->assertJsonPath('extent.x', 100)
            ->assertJsonPath('extent.y', 200)
            ->assertJsonPath('extent.width', 1024)
            ->assertJsonPath('extent.height', 1024);

        Queue::assertNothingPushed();
    }

    public function testStoreWithExtentCovered()
    {
        Queue::fake();
        config([
            'magic_sam.embedding_storage_disk' => 'test',
            'magic_sam.model_input_size' => 1024,
        ]);

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 2048,
            'height' => 2048,
        ]);

        $disk = Storage::fake('test');
        // Existing embedding at 0,0 with size 1024x1024
        $disk->put("{$image->id}/0_0_1024_1024.npy", 'abc');

        $this->beEditor();
        // Request smaller extent within the existing embedding
        // Should return the cached extent, not the requested extent
        $this
            ->postJson("/api/v1/images/{$image->id}/sam-embedding", [
                'x' => 100,
                'y' => 100,
                'width' => 500,
                'height' => 500,
            ])
            ->assertStatus(200)
            ->assertJsonPath('url', fn ($url) => !is_null($url))
            ->assertJsonPath('extent.x', 0)
            ->assertJsonPath('extent.y', 0)
            ->assertJsonPath('extent.width', 1024)
            ->assertJsonPath('extent.height', 1024);

        Queue::assertNothingPushed();
    }

    public function testStoreWithExtentExpanded()
    {
        config([
            'magic_sam.embedding_storage_disk' => 'test',
            'magic_sam.model_input_size' => 1024,
        ]);
        Queue::fake();

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 2048,
            'height' => 2048,
        ]);

        $this->beEditor();
        // Request small extent that needs to be expanded
        $this
            ->postJson("/api/v1/images/{$image->id}/sam-embedding", [
                'x' => 500,
                'y' => 500,
                'width' => 100,
                'height' => 100,
            ])
            ->assertStatus(200)
            ->assertJson([
                'url' => null,
                'extent' => [
                    'x' => 38,
                    'y' => 38,
                    'width' => 1024,
                    'height' => 1024,
                ],
            ]);

        Queue::assertPushed(function (GenerateEmbedding $job) {
            // Extent should be expanded and centered
            $this->assertEquals(1024, $job->extent['width']);
            $this->assertEquals(1024, $job->extent['height']);

            return true;
        });
    }

    public function testStoreWithExtentValidation()
    {
        config(['magic_sam.embedding_storage_disk' => 'test']);
        Queue::fake();

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 2048,
            'height' => 2048,
        ]);

        $this->beEditor();

        // Missing required params when one is provided
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding", [
            'x' => 100,
        ])->assertStatus(422);

        // Invalid negative values
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding", [
            'x' => -1,
            'y' => 0,
            'width' => 100,
            'height' => 100,
        ])->assertStatus(422);

        // Invalid zero dimensions
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding", [
            'x' => 0,
            'y' => 0,
            'width' => 0,
            'height' => 100,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function testStoreWithExtentClamped()
    {
        config([
            'magic_sam.embedding_storage_disk' => 'test',
            'magic_sam.model_input_size' => 1024,
        ]);
        Queue::fake();

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 1000,
            'height' => 1000,
        ]);

        $this->beEditor();
        // Request extent at edge that will be expanded and clamped
        $this
            ->postJson("/api/v1/images/{$image->id}/sam-embedding", [
                'x' => 900,
                'y' => 900,
                'width' => 100,
                'height' => 100,
            ])
            ->assertStatus(200)
            ->assertJson([
                'url' => null,
                'extent' => [
                    'x' => 0,
                    'y' => 0,
                    'width' => 1000,
                    'height' => 1000,
                ],
            ]);

        Queue::assertPushed(function (GenerateEmbedding $job) {
            // Extent should be expanded and clamped to fit within image
            $this->assertEquals([
                'x' => 0,
                'y' => 0,
                'width' => 1000,
                'height' => 1000,
            ], $job->extent);

            return true;
        });
    }

    public function testStoreWithExtentStartOutOfBounds()
    {
        config(['magic_sam.embedding_storage_disk' => 'test']);
        Queue::fake();

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 1000,
            'height' => 1000,
        ]);

        $this->beEditor();
        // Starting position is outside image bounds
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding", [
            'x' => 1000,
            'y' => 500,
            'width' => 100,
            'height' => 100,
        ])->assertStatus(422);

        $this->postJson("/api/v1/images/{$image->id}/sam-embedding", [
            'x' => 500,
            'y' => 1000,
            'width' => 100,
            'height' => 100,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function testStoreWithExtentEndOutOfBounds()
    {
        config(['magic_sam.embedding_storage_disk' => 'test']);
        Queue::fake();

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 1000,
            'height' => 1000,
        ]);

        $this->beEditor();
        // Extent extends beyond image width
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding", [
            'x' => 900,
            'y' => 500,
            'width' => 101,
            'height' => 100,
        ])->assertStatus(422);

        // Extent extends beyond image height
        $this->postJson("/api/v1/images/{$image->id}/sam-embedding", [
            'x' => 500,
            'y' => 900,
            'width' => 100,
            'height' => 101,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function testStoreWithExtentResolutionThreshold()
    {
        Queue::fake();
        config([
            'magic_sam.embedding_storage_disk' => 'test',
            'magic_sam.model_input_size' => 1024,
            'magic_sam.resolution_threshold' => 0.2,
        ]);

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 3000,
            'height' => 3000,
        ]);

        $disk = Storage::fake('test');
        // Cached embedding that's too large (would expand to 1024x1024, but cached is 1500x1500)
        $disk->put("{$image->id}/400_400_1500_1500.npy", 'abc');

        $this->beEditor();
        // Request small extent that would expand to 1024x1024
        // Cached 1500x1500 is 46% larger, exceeds 20% threshold
        $this
            ->postJson("/api/v1/images/{$image->id}/sam-embedding", [
                'x' => 500,
                'y' => 500,
                'width' => 100,
                'height' => 100,
            ])
            ->assertStatus(200)
            ->assertJson(['url' => null]);

        // Should generate new embedding instead of using cached
        Queue::assertPushed(GenerateEmbedding::class);
    }

    public function testStoreWithExtentResolutionThresholdAccepts()
    {
        Queue::fake();
        config([
            'magic_sam.embedding_storage_disk' => 'test',
            'magic_sam.model_input_size' => 1024,
            'magic_sam.resolution_threshold' => 0.2,
        ]);

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 3000,
            'height' => 3000,
        ]);

        $disk = Storage::fake('test');
        // Cached embedding within threshold (1100x1100 is ~7% larger than 1024x1024)
        $disk->put("{$image->id}/400_400_1100_1100.npy", 'abc');

        $this->beEditor();
        // Request small extent that would expand to 1024x1024
        $this
            ->postJson("/api/v1/images/{$image->id}/sam-embedding", [
                'x' => 500,
                'y' => 500,
                'width' => 100,
                'height' => 100,
            ])
            ->assertStatus(200)
            ->assertJsonPath('url', fn ($url) => !is_null($url))
            ->assertJsonPath('extent.x', 400)
            ->assertJsonPath('extent.y', 400)
            ->assertJsonPath('extent.width', 1100)
            ->assertJsonPath('extent.height', 1100);

        Queue::assertNothingPushed();
    }

    public function testStoreWithExtentPrefersSmallest()
    {
        Queue::fake();
        config([
            'magic_sam.embedding_storage_disk' => 'test',
            'magic_sam.model_input_size' => 1024,
            'magic_sam.resolution_threshold' => 0.2,
        ]);

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 3000,
            'height' => 3000,
        ]);

        $disk = Storage::fake('test');
        // Multiple cached embeddings within threshold
        $disk->put("{$image->id}/400_400_1200_1200.npy", 'abc');
        $disk->put("{$image->id}/400_400_1100_1100.npy", 'def');
        $disk->put("{$image->id}/400_400_1050_1050.npy", 'ghi');

        $this->beEditor();
        // Should prefer the smallest (1050x1050) for highest resolution
        $this
            ->postJson("/api/v1/images/{$image->id}/sam-embedding", [
                'x' => 500,
                'y' => 500,
                'width' => 100,
                'height' => 100,
            ])
            ->assertStatus(200)
            ->assertJsonPath('url', fn ($url) => !is_null($url))
            ->assertJsonPath('extent.width', 1050)
            ->assertJsonPath('extent.height', 1050);

        Queue::assertNothingPushed();
    }

    public function testFindCoveringEmbeddingCloseToFullImage()
    {
        Queue::fake();
        config([
            'magic_sam.embedding_storage_disk' => 'test',
            'magic_sam.model_input_size' => 1024,
            'magic_sam.resolution_threshold' => 0.2,
        ]);

        $image = Image::factory()->create([
            'volume_id' => $this->volume()->id,
            'width' => 2000,
            'height' => 2000,
        ]);

        $disk = Storage::fake('test');
        // Full image embedding exists
        $disk->put("{$image->id}.npy", 'full-image');

        $this->beEditor();
        // Request extent that's within threshold of full image (1900x1900 is 95% of 2000x2000)
        // With threshold 0.2, acceptable range is 1600-2400, so 1900 should match
        $this
            ->postJson("/api/v1/images/{$image->id}/sam-embedding", [
                'x' => 50,
                'y' => 50,
                'width' => 1900,
                'height' => 1900,
            ])
            ->assertStatus(200)
            ->assertJsonPath('url', fn ($url) => !is_null($url))
            ->assertJsonPath('extent', null);

        Queue::assertNothingPushed();
    }
}
