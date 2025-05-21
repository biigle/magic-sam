<?php

namespace Biigle\Modules\MagicSam\Jobs;

use Exception;
use FileCache;
use Biigle\User;
use Biigle\Image;
use Illuminate\Http\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Jcupitt\Vips\Image as VipsImage;
use Illuminate\Support\Facades\Cache;
use Biigle\Modules\MagicSam\Embedding;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Biigle\Modules\MagicSam\Events\EmbeddingFailed;
use Biigle\Modules\MagicSam\Events\EmbeddingAvailable;

class GenerateEmbedding
{
    use SerializesModels, Queueable;

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
     * Job can either be executed synchronously or asynchronously
     * 
     * @var bool
     */
    public $isAsync;

    /**
     * Lower left and upper right corner of viewport
     * @var array
     */
    public $extent;

    /**
     * The embedding of the image (section)
     * @var Embedding
     */
    public $embedding;

    /**
     * Array containing the group, zoom level, column and row index for each tile.
     *
     * @var array
     */
    public $tiles;

    /**
     * Extent of the tiles that are used to cover the viewport.
     *
     * @var array
     */
    public $tiledImageExtent;

    /**
     * Ignore this job if the image or user does not exist any more.
     *
     * @var bool
     */
    protected $deleteWhenMissingModels = true;

    /**
     * Create a new instance.
     *
     * @param Image $image
     * @param User $user
     * @param Request $request
     * @param bool $isAsync
     */
    public function __construct(Image $image, User $user, Request $request, $isAsync = True)
    {
        $this->image = $image;
        $this->user = $user;
        $this->isAsync = $isAsync;
        $this->extent = $request->input('extent');
        $this->tiles = $request->input('tiles', []);
        $this->tiledImageExtent = $request->input('tiledImageExtent', []);
    }

    /**
      * Handle the job.
      *
      * @return void
      */
    public function handle()
    {
        if ($this->isAsync) {
            Cache::increment(config('magic_sam.job_count_cache_key'));
        }

        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));
        $prefix = fragment_uuid_path($this->image->uuid);

        $emb = new Embedding;
        $emb->image_id = $this->image->id;
        $emb->x = $this->extent[0];
        $emb->y = $this->extent[1];
        $emb->x2 = $this->extent[2];
        $emb->y2 = $this->extent[3];

        $filenameHash = $emb->getFilenameHash();
        $embFilename = "{$filenameHash}.npy";

        $emb->filename = $embFilename;

        if (!$disk->exists($prefix)) {
            //TODO: check if not s3 disk
            $disk->makeDirectory($prefix);
        }

        try {
            $destPath = $disk->path("{$prefix}/{$embFilename}");
            if ($this->image->tiled) {
                $this->generateEmbeddingForTiledImage($embFilename, $destPath);
            } else {
                $this->generateEmbedding($embFilename, $destPath);
            }

            $this->embedding = DB::transaction(function () use ($emb) {
                $emb->save();
                return $emb;
            });

            if ($this->isAsync) {
                $this->decrementJobCacheCount();
                EmbeddingAvailable::dispatch($this->user, $emb->id, "{$prefix}/{$embFilename}", $this->extent);
            }
        } catch (Exception $e) {
            if ($this->isAsync) {
                $this->decrementJobCacheCount();
                EmbeddingFailed::dispatch($this->user);
            }
            throw $e;
        }
    }

    /**
     * Decrement job count in cache
     * 
     * @return void
     */
    protected function decrementJobCacheCount()
    {
        $job_count = config('magic_sam.job_count_cache_key');
        if (Cache::get($job_count) > 0) {
            Cache::decrement($job_count);
        } else {
            Cache::put($job_count, 0);
        }
    }

    /**
     * Generate the embedding.
     *
     * @param string $embeddingFilename
     * 
     * @return string embedding as binary
     */
    protected function generateEmbedding($embeddingFilename, $destPath)
    {
        return FileCache::getOnce($this->image, function ($file, $path) use ($embeddingFilename, $destPath) {
            // (crop and) resize image
            $image = $this->processImage($file, $path);
            // Contact the pyworker to generate the embedding
            $response = Http::withOptions([
                'sink' => $destPath // stream response body to disk
            ])
                ->attach(
                    'image',
                    $image,
                    $file->filename
                )
                ->post('http://pyworker:8080/embedding', ['filename' => $embeddingFilename]);

            if (!$response->successful()) {
                throw new Exception("The image couldn't be processed by the Magic-Sam tool. Please try again.");
            }
        });
    }

    protected function generateEmbeddingForTiledImage($embeddingFilename, $destPath)
    {
        $image = $this->createImageFromTiles();
        $response = Http::withOptions([
            'sink' => $destPath // stream response body to disk
        ])
            ->attach(
                'image',
                $image,
                $this->image->filename
            )
            ->post('http://pyworker:8080/embedding', ['filename' => $embeddingFilename]);

        if (!$response->successful()) {
            throw new Exception("The image couldn't be processed by the Magic-Sam tool. Please try again.");
        }
    }

    protected function processImage($image, $path)
    {
        $format = pathinfo($image->filename, PATHINFO_EXTENSION);

        $image = VipsImage::newFromFile($path, ['access' => 'sequential']);

        if ($this->shouldCrop($image)) {
            $image = $this->crop($image);
        }

        if ($this->shouldResize($image)) {
            $image = $this->resize($image);
        }

        return $image->writeToBuffer(".{$format}", [
            'Q' => 85,
            'strip' => true,
        ]);
    }

    protected function shouldCrop($image)
    {
        return !($this->extent[0] == 0 && $this->extent[1] == $image->height && $this->extent[2] == $image->width && $this->extent[3] == 0);
    }

    protected function shouldResize($image)
    {
        $targetSize = config('magic_sam.sam_target_size');
        return max($image->width, $image->height) != $targetSize;
    }

    protected function crop($image)
    {
        $width = $image->width;

        $extentWidth = floor(abs($this->extent[0] - $this->extent[2]));
        $extentHeight = floor(abs($this->extent[1] - $this->extent[3]));

        if ($this->image->tiled) {
            $tiledImageWidth = $this->tiledImageExtent[2] - $this->tiledImageExtent[0];
            $scale = $width / $tiledImageWidth;
            $extentWidth *= $scale;
            $extentHeight *= $scale;
            $scaledExtent = [($this->extent[0] - $this->tiledImageExtent[0]) * $scale, ($this->extent[3] - $this->tiledImageExtent[3]) * $scale];

            $image = $image->crop($scaledExtent[0], $scaledExtent[1], $extentWidth, $extentHeight);
        } else {
            $image = $image->crop($this->extent[0], $this->extent[3], $extentWidth, $extentHeight);
        }

        return $image;
    }

    protected function resize($image)
    {
        $width = $image->width;
        $height = $image->height;
        $targetSize = config('magic_sam.sam_target_size');

        if ($width > $height) {
            $height = floor(($height / $width) * $targetSize);
            $width = $targetSize;
        } else {
            $width = floor(($width / $height) * $targetSize);
            $height = $targetSize;
        }

        $image = $image->thumbnail_image($width, [
            'height' => $height,
            // Don't auto rotate thumbnails because the orientation of AUV captured
            // images is not reliable.
            'no-rotate' => true,
        ]);

        return $image;
    }

    protected function createImageFromTiles()
    {
        $tiles = collect($this->tiles);
        $tiles = $tiles->sortBy(fn($t) => $t['y']);

        $disk = Storage::disk(config('image.tiles.disk'));
        $fragment = fragment_uuid_path($this->image->uuid);
        $format = config('image.tiles.format');

        $vipsTiles = [];

        if (config('filesystems.disks.tiles.driver') == 's3') {
            //TODO: handle S3 storage
        } else {
            foreach ($tiles as $tile) {
                $group = $tile['group'];
                $filename = $tile['zoom'] . "-" . $tile['x'] . "-" . $tile['y'];
                $path = $disk->path("{$fragment}/TileGroup{$group}/{$filename}.{$format}");
                $vipsTiles[] = VipsImage::newFromFile($path, ['access' => 'sequential']);
            }
        }

        $columns = $tiles->groupBy('x')->keys()->count();

        $image = VipsImage::arrayjoin($vipsTiles, ['across' => $columns]);

        if ($this->shouldCrop($image)) {
            $image = $this->crop($image);
        }

        if ($this->shouldResize($image)) {
            $image = $this->resize($image);
        }

        return $image->writeToBuffer(".{$format}", [
            'Q' => 85,
            'strip' => true,
        ]);
    }

}
