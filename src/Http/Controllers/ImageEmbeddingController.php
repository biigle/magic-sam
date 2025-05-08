<?php

namespace Biigle\Modules\MagicSam\Http\Controllers;

use Biigle\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Biigle\Modules\MagicSam\Embedding;
use Biigle\Http\Controllers\Api\Controller;
use Biigle\Modules\MagicSam\Jobs\GenerateEmbedding;

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
    public function store(Request $request, $id)
    {
        /**
         * TODO: request validation
         * check if viewports are >= 1024x1024
         * check if coords > 0 and < image size
         */
        $image = Image::findOrFail($id);
        $this->authorize('access', $image);

        $extent = $request->input('extent');

        $emb = Embedding::where([
            'image_id' => $id,
            'x' => $extent[0],
            'y' => $extent[1],
            'x2' => $extent[2],
            'y2' => $extent[3]
        ])->first();

        //TODO: check if there is a suitable embedding already

        if ($emb) {
            $embBase64 = base64_encode($emb->getFile());
            $embId = $emb->id;
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
                        new GenerateEmbedding($image, $request->user())
                    );
                $embBase64 = null;
                $embId = null;
            } else {
                $job = new GenerateEmbedding($image, $request->user(), False, $request->input('extent'));
                $job->handle();
                $embBase64 = base64_encode($job->embedding->getFile());
                $embId = $job->embedding->id;
            }
        }

        return response()->json(['id' => $embId,'embedding' => $embBase64]);
    }
}
