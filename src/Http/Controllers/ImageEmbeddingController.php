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
     * @apiDescription This will generate a SAM embedding for the image and propagate the download URL to the user's Websockets channel. If an embedding already exists, it returns the download URL directly. Optionally accepts bbox parameters (x, y, width, height) for partial image embeddings.
     *
     * @apiParam {Number} id The image ID.
     * @apiParam {Number} [x] Bbox x coordinate (pixels).
     * @apiParam {Number} [y] Bbox y coordinate (pixels).
     * @apiParam {Number} [width] Bbox width (pixels).
     * @apiParam {Number} [height] Bbox height (pixels).
     *
     * @apiSuccessExample {json} Success response:
     * {
     *    "url": "https://example.com/storage/1.npy",
     *    "bbox": {"x": 100, "y": 200, "width": 1024, "height": 1024}
     * }
     *
     * @param StoreImageEmbeddingRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreImageEmbeddingRequest $request)
    {
        $image = $request->getImage();
        $originalBbox = $request->getBbox();
        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));

        // Expand bbox to determine target resolution.
        $expandedBbox = $originalBbox ? $this->expandBbox($image, $originalBbox) : null;

        // Check if a matching embedding already exists.
        $result = $this->findCoveringEmbedding($image, $disk, $originalBbox, $expandedBbox);
        if ($result) {
            if ($disk->providesTemporaryUrls()) {
                $url = $disk->temporaryUrl($result['filename'], now()->addHour());
            } else {
                $url = $disk->url($result['filename']);
            }

            $response = ['url' => $url];
            if ($result['bbox']) {
                $response['bbox'] = $result['bbox'];
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
                new GenerateEmbedding($image, $user, $expandedBbox)
            );

        $response = ['url' => null];
        if ($expandedBbox) {
            $response['bbox'] = $expandedBbox;
        }

        return $response;
    }

    /**
     * Find an existing embedding that covers the requested bbox
     * with resolution similar to the target expanded bbox.
     *
     * @param Image $image
     * @param $disk Storage disk
     * @param array|null $originalBbox The original requested bbox
     * @param array|null $expandedBbox The expanded bbox (target resolution)
     * @return array|null ['filename' => string, 'bbox' => array|null]
     */
    protected function findCoveringEmbedding(Image $image, $disk, ?array $originalBbox, ?array $expandedBbox): ?array
    {
        // Use the image dimensions as fallback for the check if the full image embedding
        // can be used for a large requested bbox.
        $expandedWidth = $expandedBbox['width'] ?? $image->width;
        $expandedHeight = $expandedBbox['height'] ?? $image->height;

        // Only cached embeddings are considered that have a width and height similar
        // to the (expanded) requested bbox, within the configured tolerance.
        $threshold = config('magic_sam.resolution_threshold');
        $minWidth = $expandedWidth * (1 - $threshold);
        $maxWidth = $expandedWidth * (1 + $threshold);
        $minHeight = $expandedHeight * (1 - $threshold);
        $maxHeight = $expandedHeight * (1 + $threshold);

        // Take the full image embedding if no bbox was requested or the requested
        // bbox almost matches the full image dimensions.
        if (
            !$originalBbox  ||
            ($image->width >= $minWidth &&
            $image->width <= $maxWidth &&
            $image->height >= $minHeight &&
            $image->height <= $maxHeight)
        ) {
            $filename = "{$image->id}.npy";

            if ($disk->exists($filename)) {
                return ['filename' => $filename, 'bbox' => null];
            }

            return null;
        }


        // Otherwise we look for an embedding matching the bbox.
        $directory = (string) $image->id;

        if (!$disk->exists($directory)) {
            return null;
        }

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

            // Ignore if cached bbox does not cover requested bbox.
            if (
                $cached['x'] > $originalBbox['x'] ||
                $cached['y'] > $originalBbox['y'] ||
                $cached['x'] + $cached['width'] < $originalBbox['x'] + $originalBbox['width'] ||
                $cached['y'] + $cached['height'] < $originalBbox['y'] + $originalBbox['height']
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
                $bestMatch = ['filename' => $file, 'bbox' => $cached];
            }
        }

        return $bestMatch;
    }

    /**
     * Expand bbox to a square with at least the minimum model input size.
     */
    protected function expandBbox(Image $image, array $bbox): array
    {
        $minSize = config('magic_sam.model_input_size');

        // Determine target size: at least minSize, and square (use largest dimension).
        $targetSize = max($minSize, $bbox['width'], $bbox['height']);

        // Expand width (centered).
        if ($bbox['width'] < $targetSize) {
            $expand = $targetSize - $bbox['width'];
            $bbox['x'] = max(0, $bbox['x'] - intval($expand / 2));
            $bbox['width'] = min($targetSize, $image->width);
        }

        // Expand height (centered).
        if ($bbox['height'] < $targetSize) {
            $expand = $targetSize - $bbox['height'];
            $bbox['y'] = max(0, $bbox['y'] - intval($expand / 2));
            $bbox['height'] = min($targetSize, $image->height);
        }

        // Clamp to image bounds.
        if ($bbox['x'] + $bbox['width'] > $image->width) {
            $bbox['x'] = max(0, $image->width - $bbox['width']);
        }
        if ($bbox['y'] + $bbox['height'] > $image->height) {
            $bbox['y'] = max(0, $image->height - $bbox['height']);
        }

        return $bbox;
    }
}
