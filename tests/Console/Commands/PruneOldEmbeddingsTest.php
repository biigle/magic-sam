<?php

namespace Biigle\Tests\Modules\MagicSam\Console\Commands;

use Illuminate\Support\Facades\Storage;
use TestCase;

class PruneOldEmbeddingsTest extends TestCase
{
    public function testHandle()
    {
        config(['magic_sam.embedding_storage_disk' => 'test']);
        config(['magic_sam.prune_age_days' => 1]);
        $disk = Storage::fake('test');
        $root = $disk->getConfig()['root'];

        $disk->put('1.npy', 'abc');
        $disk->put('2.npy', 'abc');

        // Timestamp is 2 days ago.
        touch("{$root}/1.npy", time() - 172800);

        $this->artisan('magic-sam:prune-embeddings')->assertExitCode(0);

        $disk->assertMissing('1.npy');
        $disk->assertExists('2.npy');
    }

    public function testHandleExtentEmbeddings()
    {
        config(['magic_sam.embedding_storage_disk' => 'test']);
        config(['magic_sam.prune_age_days' => 1]);
        $disk = Storage::fake('test');
        $root = $disk->getConfig()['root'];

        // Extent-based embeddings in subdirectories
        $disk->put('1/100_200_1024_1024.npy', 'abc');
        $disk->put('1/500_500_1024_1024.npy', 'abc');
        $disk->put('2/0_0_1024_1024.npy', 'abc');

        // Make one extent-based file old
        touch("{$root}/1/100_200_1024_1024.npy", time() - 172800);

        $this->artisan('magic-sam:prune-embeddings')->assertExitCode(0);

        $disk->assertMissing('1/100_200_1024_1024.npy');
        $disk->assertExists('1/500_500_1024_1024.npy');
        $disk->assertExists('2/0_0_1024_1024.npy');
    }

    public function testHandleDeletesEmptyDirectories()
    {
        config(['magic_sam.embedding_storage_disk' => 'test']);
        config(['magic_sam.prune_age_days' => 1]);
        $disk = Storage::fake('test');
        $root = $disk->getConfig()['root'];

        // Single extent-based embedding that will be pruned
        $disk->put('1/100_200_1024_1024.npy', 'abc');

        // Make the file old
        touch("{$root}/1/100_200_1024_1024.npy", time() - 172800);

        $this->artisan('magic-sam:prune-embeddings')->assertExitCode(0);

        $disk->assertMissing('1/100_200_1024_1024.npy');
        $this->assertFalse($disk->exists('1'));
    }
}
