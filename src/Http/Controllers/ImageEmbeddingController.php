<?php

namespace Biigle\Modules\MagicSam\Http\Controllers;

use Biigle\Http\Controllers\Api\Controller;
use Biigle\Image;
use Biigle\Modules\MagicSam\Http\Requests\StoreImageEmbeddingRequest;
use Biigle\Modules\MagicSam\Jobs\GenerateEmbedding;
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
     * @apiDescription This will generate a SAM embedding for the image and propagate the download URL to the user's Websockets channel. If an embedding already exists, it returns the download URL directly. Optionally accepts extent parameters (x, y, width, height) for partial image embeddings.
     *
     * @apiParam {Number} id The image ID.
     * @apiParam {Number} [x] Extent x coordinate (pixels).
     * @apiParam {Number} [y] Extent y coordinate (pixels).
     * @apiParam {Number} [width] Extent width (pixels).
     * @apiParam {Number} [height] Extent height (pixels).
     *
     * @apiSuccessExample {json} Success response:
     * {
     *    "url": "https://example.com/storage/1.npy",
     *    "extent": {"x": 100, "y": 200, "width": 1024, "height": 1024}
     * }
     *
     * @param StoreImageEmbeddingRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreImageEmbeddingRequest $request)
    {
        $image = $request->getImage();
        $extent = $request->getExtent();
        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));

        // Check if a matching embedding already exists.
        $result = $this->findCoveringEmbedding($image, $disk, $extent);
        if ($result) {
            if ($disk->providesTemporaryUrls()) {
                $url = $disk->temporaryUrl($result['filename'], now()->addHour());
            } else {
                $url = $disk->url($result['filename']);
            }

            $response = ['url' => $url];
            if ($result['extent']) {
                $response['extent'] = $result['extent'];
            }

            return $response;
        }

        // Otherwise generate a new embedding.
        $user = $request->user();
        $cacheKey = GenerateEmbedding::getPendingJobsCacheKey($user);
        $maxParallelJobs = config('magic_sam.max_parallel_jobs_per_user');
        $currentCount = Cache::get($cacheKey, 0);

        if ($currentCount >= $maxParallelJobs) {
            throw new TooManyRequestsHttpException(message: "You already have a SAM job running. Please wait for the one to finish until you submit a new one.");
        }

        Cache::increment($cacheKey);

        if ($extent) {
            $extent = $this->expandExtent($image, $extent);
        }

        Queue::connection(config('magic_sam.request_connection'))
            ->pushOn(
                config('magic_sam.request_queue'),
                new GenerateEmbedding($image, $user, $extent)
            );

        $response = ['url' => null];
        if ($extent) {
            $response['extent'] = $extent;
        }

        return $response;
    }

    /**
     * Find an existing extent-based embedding that covers the requested extent.
     *
     * @return array|null ['filename' => string, 'extent' => array|null]
     */
    protected function findCoveringEmbedding(Image $image, $disk, ?array $extent): ?array
    {
        // Without extent the user requests the embedding for the whole image.
        if (is_null($extent)) {
            $filename = "{$image->id}.npy";

            return $disk->exists($filename) ? ['filename' => $filename, 'extent' => null] : null;
        }


        // Otherwise we look for an embedding matching the extent.
        $directory = (string) $image->id;

        if (!$disk->exists($directory)) {
            return null;
        }

        $minSize = config('magic_sam.model_input_size');

        foreach ($disk->files($directory) as $file) {
            $basename = pathinfo($file, PATHINFO_FILENAME);
            $parts = explode('_', $basename);
            if (count($parts) !== 4) {
                continue;
            }

            $cached = [
                'x' => (int) $parts[0],
                'y' => (int) $parts[1],
                'width' => (int) $parts[2],
                'height' => (int) $parts[3],
            ];

            // Check if cached fully covers requested
            if ($cached['x'] <= $extent['x'] &&
                $cached['y'] <= $extent['y'] &&
                $cached['x'] + $cached['width'] >= $extent['x'] + $extent['width'] &&
                $cached['y'] + $cached['height'] >= $extent['y'] + $extent['height']) {

                return ['filename' => $file, 'extent' => $cached];
            }
        }

        return null;
    }

    /**
     * Expand extent to minimum model input size if needed.
     */
    protected function expandExtent(Image $image, array $extent): array
    {
        $minSize = config('magic_sam.model_input_size');

        // Expand width if needed (centered)
        if ($extent['width'] < $minSize) {
            $expand = $minSize - $extent['width'];
            $extent['x'] = max(0, $extent['x'] - intval($expand / 2));
            $extent['width'] = min($minSize, $image->width);
        }

        // Expand height if needed (centered)
        if ($extent['height'] < $minSize) {
            $expand = $minSize - $extent['height'];
            $extent['y'] = max(0, $extent['y'] - intval($expand / 2));
            $extent['height'] = min($minSize, $image->height);
        }

        // Clamp to image bounds
        if ($extent['x'] + $extent['width'] > $image->width) {
            $extent['x'] = max(0, $image->width - $extent['width']);
        }
        if ($extent['y'] + $extent['height'] > $image->height) {
            $extent['y'] = max(0, $image->height - $extent['height']);
        }

        return $extent;
    }
}
