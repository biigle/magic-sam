<?php

namespace Biigle\Modules\MagicSam\Http\Controllers;

use Biigle\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        $image = Image::findOrFail($id);
        $this->authorize('access', $image);

        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));
        $filename = "{$image->id}.npy";
        $embedding = null;
        if ($disk->exists($filename)) {
            $embedding = base64_encode($disk->get($filename));
        } else {
            $job_count = config('magic_sam.job_count_cache_key');
            if (!Cache::has($job_count)) {
                Cache::add($job_count, 1);
            }

            if (Cache::get($job_count) > config('magic_sam.queue_threshold')) {
                Queue::connection(config('magic_sam.request_connection'))
                    ->pushOn(
                        config('magic_sam.request_queue'),
                        new GenerateEmbedding($image, $request->user()));
                $embedding = null;
            } else {
                $job = new GenerateEmbedding($image, $request->user(), False);
                $job->handle();
                $embedding = base64_encode($disk->get($filename));
            }
        }

        return response()->json(['embedding' => $embedding]);
    }
}
