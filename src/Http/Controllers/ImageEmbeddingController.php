<?php

namespace Biigle\Modules\MagicSam\Http\Controllers;

use Biigle\Http\Controllers\Api\Controller;
use Biigle\Image;
use Biigle\Modules\MagicSam\Jobs\GenerateEmbedding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

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
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $id)
    {
        $image = Image::findOrFail($id);
        $this->authorize('access', $image);

        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));
        $filename = "{$image->id}.npy";

        if ($disk->exists($filename)) {
            if ($disk->providesTemporaryUrls()) {
                $url = $disk->temporaryUrl($filename, now()->addHour());
            } else {
                $url = $disk->url($filename);
            }

            return ['url' => $url];
        }

        $user = $request->user();
        $cacheKey = GenerateEmbedding::getPendingJobsCacheKey($user);
        $maxParallelJobs = config('magic_sam.max_parallel_jobs_per_user');
        $currentCount = Cache::get($cacheKey, 0);

        if ($currentCount >= $maxParallelJobs) {
            throw new TooManyRequestsHttpException(message: "You already have a SAM job running. Please wait for the one to finish until you submit a new one.");
        }

        Cache::increment($cacheKey);

        Queue::connection(config('magic_sam.request_connection'))
            ->pushOn(
                config('magic_sam.request_queue'),
                new GenerateEmbedding($image, $user)
            );

        return ['url' => null];
    }
}
