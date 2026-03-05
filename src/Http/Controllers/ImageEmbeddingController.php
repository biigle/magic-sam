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
        $originalExtent = $request->getExtent();
        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));

        // Expand extent to determine target resolution
        $expandedExtent = $originalExtent ? $this->expandExtent($image, $originalExtent) : null;

        // Check if a matching embedding already exists.
        $result = $this->findCoveringEmbedding($image, $disk, $originalExtent, $expandedExtent);
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

        Queue::connection(config('magic_sam.request_connection'))
            ->pushOn(
                config('magic_sam.request_queue'),
                new GenerateEmbedding($image, $user, $expandedExtent)
            );

        $response = ['url' => null];
        if ($expandedExtent) {
            $response['extent'] = $expandedExtent;
        }

        return $response;
    }

    /**
     * Find an existing extent-based embedding that covers the requested extent
     * with resolution similar to the target expanded extent.
     *
     * @param Image $image
     * @param $disk Storage disk
     * @param array|null $originalExtent The original requested extent
     * @param array|null $expandedExtent The expanded extent (target resolution)
     * @return array|null ['filename' => string, 'extent' => array|null]
     */
    protected function findCoveringEmbedding(Image $image, $disk, ?array $originalExtent, ?array $expandedExtent): ?array
    {
        // Without extent the user requests the embedding for the whole image.
        if (is_null($originalExtent)) {
            $filename = "{$image->id}.npy";

            if ($disk->exists($filename)) {
                return ['filename' => $filename, 'extent' => null];
            }

            return null;
        }


        // Otherwise we look for an embedding matching the extent.
        $directory = (string) $image->id;

        if (!$disk->exists($directory)) {
            return null;
        }

        // Only cached embeddings are considered that have a width and height similar
        // to the (expanded) requested extent, within the configured tolerance.
        $threshold = config('magic_sam.resolution_threshold');
        $minWidth = $expandedExtent['width'] * (1 - $threshold);
        $maxWidth = $expandedExtent['width'] * (1 + $threshold);
        $minHeight = $expandedExtent['height'] * (1 - $threshold);
        $maxHeight = $expandedExtent['height'] * (1 + $threshold);

        $bestMatch = null;
        $smallestArea = PHP_INT_MAX;

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

            // Ignore if cached extent does not cover requested extent.
            if (
                $cached['x'] > $originalExtent['x'] ||
                $cached['y'] > $originalExtent['y'] ||
                $cached['x'] + $cached['width'] < $originalExtent['x'] + $originalExtent['width'] ||
                $cached['y'] + $cached['height'] < $originalExtent['y'] + $originalExtent['height']
            ) {
                continue;
            }

            // Ignore if cached size is not similar to requested size.
            if (
                $cached['width'] < $minWidth ||
                $cached['width'] > $maxWidth ||
                $cached['height'] < $minHeight ||
                $cached['height'] > $maxHeight
            ) {
                continue;
            }

            // Prefer smallest size (i.e. highest resolution).
            $area = $cached['width'] * $cached['height'];
            if ($area < $smallestArea) {
                $smallestArea = $area;
                $bestMatch = ['filename' => $file, 'extent' => $cached];
            }
        }

        return $bestMatch;
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
