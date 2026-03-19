<?php

namespace Biigle\Modules\MagicSam\Jobs;

use Biigle\Image;
use Biigle\Modules\MagicSam\Events\EmbeddingAvailable;
use Biigle\Modules\MagicSam\Events\EmbeddingFailed;
use Biigle\User;
use Exception;
use FileCache;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Jcupitt\Vips\Image as VipsImage;

class GenerateEmbedding
{
    use SerializesModels, Queueable;

    /**
     * Maximum number of Zoomify tiles to load before falling back to full image.
     * If a bbox requires more tiles than this, loading the full image is faster
     * due to reduced request overhead.
     */
    const MAX_TILES_THRESHOLD = 100;

    /**
     * Zoomify tile size in pixels (standard for Zoomify format).
     */
    const ZOOMIFY_TILE_SIZE = 256;

    /**
     * The image to generate an embedding of.
     *
     * @var Image
     */
    public $image;

    /**
     * The user who initiated the job.
     *
     * @var User
     */
    public $user;

    /**
     * The bbox of the image to generate an embedding for (x, y, width, height).
     *
     * @var array|null
     */
    public $bbox;

    /**
     * Ignore this job if the image or user does not exist any more.
     *
     * @var bool
     */
    protected $deleteWhenMissingModels = true;

    /**
     * The number of times the job may be attempted.
     *
     * We only try one because the failed event is sent and shown to the user
     * immediately.
     */
    public $tries = 1;

    /**
     * Create a new instance.
     *
     * @param Image $image
     * @param User $user
     * @param array|null $bbox Optional bbox (x, y, width, height)
     */
    public function __construct(Image $image, User $user, ?array $bbox = null)
    {
        $this->image = $image;
        $this->user = $user;
        $this->bbox = $bbox;
    }

    /**
     * Get the cache key for tracking pending jobs for a user.
     */
    public static function getPendingJobsCacheKey(User $user): string
    {
        return "magic_sam.pending_jobs.{$user->id}";
    }

    /**
      * Handle the job.
      *
      * @return void
      */
    public function handle()
    {
        $filename = $this->getFilename();
        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));
        try {
            if (!$disk->exists($filename)) {
                $embedding = $this->generateEmbedding($this->image);
                $disk->put($filename, $embedding);
            }

            EmbeddingAvailable::dispatch($filename, $this->user, $this->bbox);
        } catch (Exception $e) {
            EmbeddingFailed::dispatch($this->user);
            throw $e;
        } finally {
            $this->decrementPendingJobsCounter();
        }
    }

    /**
     * Get the filename for the embedding.
     */
    public function getFilename(): string
    {
        if ($this->bbox) {
            return "{$this->image->id}/{$this->bbox['x']}_{$this->bbox['y']}_{$this->bbox['width']}_{$this->bbox['height']}.npy";
        }

        return "{$this->image->id}.npy";
    }

    /**
     * Generate the embedding.
     */
    protected function generateEmbedding(Image $image): string
    {
        return FileCache::getOnce($image, function ($file, $path) {
            $buffer = $this->getImageBufferForPyworker($path);
            $embedding = $this->sendPyworkerRequest($buffer);

            return $embedding;
        });
    }

    /**
     * Get the byte string of the resized image for the Python worker.
     */
    protected function getImageBufferForPyworker(string $path): string
    {
        $inputSize = config('magic_sam.model_input_size');

        if ($this->image->tiled && $this->bbox) {
            $tileInfo = $this->prepareTileLoadingInfo($this->bbox, $inputSize);

            if ($tileInfo['tile_count'] <= self::MAX_TILES_THRESHOLD) {
                try {
                    return $this->getImageBufferFromZoomifyTiles($tileInfo, $inputSize);
                } catch (Exception $e) {
                    Log::warning("Failed to load Zoomify tiles for image {$this->image->id}, falling back to full image: {$e->getMessage()}");
                }
            }
        }

        $options = ['access' => 'sequential'];
        // Make sure the image is in RGB format before sending it to the pyworker.
        $image = VipsImage::newFromFile($path, $options)->colourspace('srgb');
        if ($image->hasAlpha()) {
            $image = $image->flatten();
        }

        if ($this->bbox) {
            $image = $image->crop(
                $this->bbox['x'],
                $this->bbox['y'],
                $this->bbox['width'],
                $this->bbox['height']
            );
        }

        $factor = $inputSize / max($image->width, $image->height);
        if ($factor < 1) {
            $image = $image->resize($factor);
        }

        return $image->writeToBuffer('.png');
    }

    /**
     * Send the scaled-down PNG image to the Python worker and return the embedding npy
     * file as binary blob.
     */
    protected function sendPyworkerRequest(string $buffer): string
    {
        $url = config('magic_sam.generate_embedding_worker_url');
        $timeout = config('magic_sam.worker_timeout');
        $response = Http::withBody($buffer, 'image/png')
            ->timeout($timeout)
            ->post($url);
        if ($response->successful()) {
            return $response->body();
        } else {
            $pyException = $response->body();
            throw new Exception("Error in pyworker:\n {$pyException}");
        }
    }

    /**
     * Calculate the maximum Zoomify zoom level (full resolution) for this image.
     *
     * The max level is determined by how many zoom levels are needed so that
     * 256 × 2^maxLevel >= max(image_width, image_height).
     *
     * See prepareTileLoadingInfo() fore more explanation.
     */
    protected function getMaxZoomLevel(): int
    {
        return (int) ceil(
            log(
                max($this->image->width, $this->image->height) / self::ZOOMIFY_TILE_SIZE,
                2
            )
        );
    }

    /**
     * Prepare all information needed for loading Zoomify tiles.
     *
     * This calculates:
     * 1. Best zoom level to use (lowest resolution where bbox >= modelInputSize)
     * 2. Scale factor for that zoom level
     * 3. Bbox coordinates at that zoom level
     * 4. Which tiles (rows/cols) are needed
     * 5. Total tile count
     * 6. Number of tiles before this zoom level (for TileGroup index calculation)
     * 7. Number of tile columns at this zoom level (for TileGroup index calculation)
     *
     * Zoomify structure:
     * - Level 0: Lowest resolution (single 256x256 tile)
     * - Level N: Higher resolution (dimension = 256 × 2^N)
     * - Max level: Full resolution
     *
     * Zoom level selection math:
     * - At max zoom level (full res): bbox has dimensions from request
     * - At lower levels: bbox_dimension_at_level = bbox_dimension × 2^(level - maxLevel)
     * - We need: max(bbox_width, bbox_height) × 2^(level - maxLevel) >= modelInputSize
     * - Solving: level >= maxLevel + log2(modelInputSize / max(bbox_width, bbox_height))
     *
     */
    protected function prepareTileLoadingInfo(array $bbox, int $modelInputSize): array
    {
        $maxZoomLevel = $this->getMaxZoomLevel();

        $levelDiff =
            ceil(
                log(
                    $modelInputSize / max($bbox['width'], $bbox['height']),
                    2
                )
            );

        $targetLevel = $maxZoomLevel + $levelDiff;
        $zoomLevel = (int) max(0, min($targetLevel, $maxZoomLevel));

        $scale = pow(2, $zoomLevel - $maxZoomLevel);
        $bboxAtLevel = [
            'x' => (int) floor($bbox['x'] * $scale),
            'y' => (int) floor($bbox['y'] * $scale),
            'width' => (int) ceil($bbox['width'] * $scale),
            'height' => (int) ceil($bbox['height'] * $scale),
        ];

        $colStart = floor($bboxAtLevel['x'] / self::ZOOMIFY_TILE_SIZE);
        $colEnd = floor(($bboxAtLevel['x'] + $bboxAtLevel['width'] - 1) / self::ZOOMIFY_TILE_SIZE);
        $rowStart = floor($bboxAtLevel['y'] / self::ZOOMIFY_TILE_SIZE);
        $rowEnd = floor(($bboxAtLevel['y'] + $bboxAtLevel['height'] - 1) / self::ZOOMIFY_TILE_SIZE);

        $tilesWide = $colEnd - $colStart + 1;
        $tilesHigh = $rowEnd - $rowStart + 1;
        $tileCount = $tilesWide * $tilesHigh;

        // Count tiles in all levels before $zoomLevel for TileGroup index calculation.
        $tilesBeforeLevel = 0;
        for ($level = 0; $level < $zoomLevel; $level++) {
            $levelScale = pow(2, $level - $maxZoomLevel);
            $tilesBeforeLevel += ceil($this->image->width * $levelScale / self::ZOOMIFY_TILE_SIZE)
                * ceil($this->image->height * $levelScale / self::ZOOMIFY_TILE_SIZE);
        }

        $tilesWideAtLevel = ceil($this->image->width * $scale / self::ZOOMIFY_TILE_SIZE);

        return [
            'zoom_level' => $zoomLevel,
            'scale' => $scale,
            'bbox_at_level' => $bboxAtLevel,
            'col_start' => (int) $colStart,
            'col_end' => (int) $colEnd,
            'row_start' => (int) $rowStart,
            'row_end' => (int) $rowEnd,
            'tile_count' => (int) $tileCount,
            'tiles_before_level' => (int) $tilesBeforeLevel,
            'tiles_wide_at_level' => (int) $tilesWideAtLevel,
        ];
    }

    /**
     * Load and stitch Zoomify tiles for the bbox, then resize for the Python worker.
     *
     * @param array $tileInfo Tile loading information from prepareTileLoadingInfo()
     * @param int $modelInputSize Target size for the model
     * @return string PNG buffer for the Python worker
     */
    protected function getImageBufferFromZoomifyTiles(array $tileInfo, int $modelInputSize): string
    {
        $disk = Storage::disk(config('image.tiles.disk'));
        $basePath = fragment_uuid_path($this->image->uuid);
        $zoomLevel = $tileInfo['zoom_level'];

        // Zoomify assigns each tile a global sequential index across all zoom levels.
        // Every 256 tiles are grouped into a TileGroup directory. The index is computed
        // as: tiles_before_level + row * tiles_wide_at_level + col.
        $tilesBeforeLevel = $tileInfo['tiles_before_level'];
        $tilesWideAtLevel = $tileInfo['tiles_wide_at_level'];

        $tileImages = [];
        for ($row = $tileInfo['row_start']; $row <= $tileInfo['row_end']; $row++) {
            for ($col = $tileInfo['col_start']; $col <= $tileInfo['col_end']; $col++) {
                $tileIndex = $tilesBeforeLevel + $row * $tilesWideAtLevel + $col;
                $tileGroup = (int) floor($tileIndex / 256);
                $tilePath = "{$basePath}/TileGroup{$tileGroup}/{$zoomLevel}-{$col}-{$row}.jpg";

                $tileImages[] = VipsImage::newFromBuffer($disk->get($tilePath));
            }
        }

        $tilesAcross = $tileInfo['col_end'] - $tileInfo['col_start'] + 1;
        $stitched = VipsImage::arrayjoin($tileImages, ['across' => $tilesAcross])
            ->colourspace('srgb');

        if ($stitched->hasAlpha()) {
            $stitched = $stitched->flatten();
        }

        // Tiles extend beyond the bbox boundaries so we need to crop to the exact
        // bbox area. Bbox coordinates must be adjusted to be relative to the top-left
        // corner of the stitched tile grid (which starts at col_start/row_start).
        $cropX = $tileInfo['bbox_at_level']['x'] - $tileInfo['col_start'] * self::ZOOMIFY_TILE_SIZE;
        $cropY = $tileInfo['bbox_at_level']['y'] - $tileInfo['row_start'] * self::ZOOMIFY_TILE_SIZE;
        $image = $stitched->crop($cropX, $cropY, $tileInfo['bbox_at_level']['width'], $tileInfo['bbox_at_level']['height']);

        $factor = $modelInputSize / max($image->width, $image->height);
        if ($factor < 1) {
            $image = $image->resize($factor);
        }

        return $image->writeToBuffer('.png');
    }

    /**
     * Decrement the pending jobs counter for the user.
     */
    protected function decrementPendingJobsCounter(): void
    {
        $cacheKey = static::getPendingJobsCacheKey($this->user);
        Cache::decrement($cacheKey);

        if (Cache::get($cacheKey, 0) <= 0) {
            Cache::forget($cacheKey);
        }
    }
}
