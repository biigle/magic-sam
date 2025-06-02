<?php

namespace Biigle\Modules\MagicSam\Listeners;

use Biigle\Events\ImagesDeleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Storage;

class CleanupImageEmbeddings implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(ImagesDeleted $event): void
    {
        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));

        foreach ($event->uuids as $uuid) {
            $prefix = fragment_uuid_path($uuid);
            $disk->deleteDirectory("{$prefix}");
        }
    }
}
