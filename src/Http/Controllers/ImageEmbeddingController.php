<?php

namespace Biigle\Modules\MagicSam\Http\Controllers;

use Biigle\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Biigle\Modules\MagicSam\Embedding;
use Illuminate\Support\Facades\Storage;
use Biigle\Http\Controllers\Api\Controller;
use Biigle\Modules\MagicSam\Jobs\GenerateEmbedding;
use Biigle\Modules\MagicSam\Http\Requests\StoreEmbedding;

class ImageEmbeddingController extends Controller
{
    /**
     * Request a SAM image embedding.
     *
     * @api {post} images/:id/sam-embedding Request SAM embedding
     * @apiGroup Images
     * @apiName StoreSamEmbedding
     * @apiPermission projectMember
     * @apiDescription This will generate a SAM embedding for the image and propagate the download URL to the user's Websockets channel. If an embedding already exists, it returns the embedding directly.
     *
     * @apiParam {Number} id The image ID.
     *
     * @header {X-Embedding-Id} The used embedding's id
     * @header {X-Embedding-Extent} The embedding's extent coordinates (e.g [0,1,1,0])
     *
     * @param Request $request
     * @param int $id
     */
    public function store(StoreEmbedding $request, $id)
    {
        $image = Image::findOrFail($id);
        $prefix = fragment_uuid_path($image->uuid);
        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));

        $extent = $request->input('extent');
        $excludedEmbId = $request->input('excludeEmbeddingId', 0);

        $embId = null;
        $embExtent = null;

        $emb = Embedding::getNearestEmbedding($id, $extent, $excludedEmbId);

        if ($emb) {
            $embId = $emb->id;
            $embExtent = $emb->getExtent();
            $file = $disk->path("{$prefix}/".$emb->filename);
        } else {
            $job_count = config('magic_sam.job_count_cache_key');
            if (!Cache::has($job_count)) {
                Cache::add($job_count, 1);
            }

            $shouldBeQueued = Cache::get($job_count) > config('magic_sam.queue_threshold');

            if ($shouldBeQueued) {
                Queue::connection(config('magic_sam.request_connection'))
                    ->pushOn(
                        config('magic_sam.request_queue'),
                        new GenerateEmbedding($image, $request->user(), $request)
                    );

                return;
            } else {
                $job = new GenerateEmbedding($image, $request->user(), $request, False);
                $job->handle();
                $embId = $job->embedding->id;
                $embExtent = $job->embedding->getExtent();
                $prefix = fragment_uuid_path($image->uuid);
                $file = $disk->path("{$prefix}/".$job->embedding->filename);
            }
        }

        return response()->file($file, [
            'Content-Type' => 'application/octet-stream',
            'X-Embedding-Id' => $embId,
            'X-Embedding-Extent' => json_encode($embExtent)
        ]);
    }
}
