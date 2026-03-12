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
     * @param array|null $expandedBbox The expanded bbox (target resolution), or null if full image should be used
     * @return array|null ['filename' => string, 'bbox' => array|null]
     */
    protected function findCoveringEmbedding(Image $image, $disk, ?array $originalBbox, ?array $expandedBbox): ?array
    {
        // If no expanded bbox, we want the full image embedding.
        // This happens when no bbox was requested, or when the requested bbox
        // is almost as large as the full image.
        if (!$expandedBbox) {
            $filename = "{$image->id}.npy";
            if ($disk->exists($filename)) {
                return ['filename' => $filename, 'bbox' => null];
            }
            return null;
        }

        // Only cached embeddings are considered that have a width and height similar
        // to the (expanded) requested bbox, within the configured tolerance.
        $range = $this->getResolutionRange($expandedBbox['width'], $expandedBbox['height']);

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
                $cached['width'] < $range['minWidth'] ||
                $cached['width'] > $range['maxWidth'] ||
                $cached['height'] < $range['minHeight'] ||
                $cached['height'] > $range['maxHeight']
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
     * Returns null if the expanded bbox would be almost as large as the full image.
     */
    protected function expandBbox(Image $image, array $bbox): ?array
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

        // If the expanded bbox is almost as large as the full image,
        // return null to trigger full image embedding generation instead.
        $range = $this->getResolutionRange($bbox['width'], $bbox['height']);

        if (
            // Tiled images never get a full-image embedding.
            !$image->tiled &&
            $image->width >= $range['minWidth'] &&
            $image->width <= $range['maxWidth'] &&
            $image->height >= $range['minHeight'] &&
            $image->height <= $range['maxHeight']
        ) {
            return null;
        }

        return $bbox;
    }

    /**
     * Calculate resolution range for matching embeddings within threshold.
     *
     * @param int $width
     * @param int $height
     * @return array ['minWidth' => float, 'maxWidth' => float, 'minHeight' => float, 'maxHeight' => float]
     */
    protected function getResolutionRange(int $width, int $height): array
    {
        $threshold = config('magic_sam.resolution_threshold');

        return [
            'minWidth' => $width * (1 - $threshold),
            'maxWidth' => $width * (1 + $threshold),
            'minHeight' => $height * (1 - $threshold),
            'maxHeight' => $height * (1 + $threshold),
        ];
    }
}
