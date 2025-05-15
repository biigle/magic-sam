<?php

namespace Biigle\Modules\MagicSam\Http\Controllers;

use Biigle\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Biigle\Modules\MagicSam\Embedding;
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
     * @apiDescription This will generate a SAM embedding for the image and propagate the download URL to the user's Websockets channel. If an embedding already exists, it returns the download URL directly.
     *
     * @apiParam {Number} id The image ID.
     *
     * @apiSuccessExample {json} Success response:
     * {
     *    "url": "https://example.com/storage/1.npy"
     * }
     *
     * @param Request $request
     * @param int $id
     */
    public function store(StoreEmbedding $request, $id)
    {
        $image = Image::findOrFail($id);
        $extent = $request->input('extent');

        $emb = Embedding::getNearestEmbedding($id, $extent);
        if ($emb) {
            $embBase64 = base64_encode($emb->getFile());
            $embId = $emb->id;
            $embExtent = $emb->getExtent();
        } else {
            $job_count = config('magic_sam.job_count_cache_key');
            if (!Cache::has($job_count)) {
                Cache::add($job_count, 1);
            }

            $shouldBeQueued = $image->tiled || Cache::get($job_count) > config('magic_sam.queue_threshold');

            if ($shouldBeQueued) {
                Queue::connection(config('magic_sam.request_connection'))
                    ->pushOn(
                        config('magic_sam.request_queue'),
                        new GenerateEmbedding($image, $request->user(), true, $request->input('extent'))
                    );
                $embBase64 = null;
                $embId = null;
                $embExtent = null;
            } else {
                $job = GenerateEmbedding::dispatchSync($image, $request->user(), $request->input('extent'), False);
                $embBase64 = base64_encode($job->embedding->getFile());
                $embId = $job->embedding->id;
                $embExtent = $job->embedding->getExtent();
            }
        }

        return response()->json(['id' => $embId,'embedding' => $embBase64, 'extent' => $embExtent]);
    }
}
