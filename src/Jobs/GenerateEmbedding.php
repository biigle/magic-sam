<?php

namespace Biigle\Modules\MagicSam\Jobs;

use Exception;
use FileCache;
use Biigle\User;
use Biigle\Image;
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
     * @param bool $isAsync
     */
    public function __construct(Image $image, User $user, array $extent, bool $isAsync = True)
    {
        $this->image = $image;
        $this->user = $user;
        $this->isAsync = $isAsync;
        $this->extent = $extent;
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

        if(!$disk->exists($prefix)) {
            $disk->makeDirectory($prefix);
        }

        try {
            $this->generateEmbedding($emb, $embFilename, $disk->path("{$prefix}/{$embFilename}"));

            if ($this->isAsync) {
                $this->decrementJobCacheCount();
                EmbeddingAvailable::dispatch("{$prefix}/{$embFilename}", $this->user, $this->extent);
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
    protected function generateEmbedding($emb, $embeddingFilename, $destPath)
    {
        return FileCache::getOnce($this->image, function ($file, $path) use ($emb, $embeddingFilename, $destPath) {
            // (crop and) resize image
            $image = $this->processImage($path);
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

            $this->embedding = DB::transaction(function () use ($emb) {
                $emb->save();
                return $emb;
            });
        });
    }

    protected function processImage($path)
    {
        $width = $this->image->width;
        $height = $this->image->height;
        $format = pathinfo($this->image->filename, PATHINFO_EXTENSION);

        $image = VipsImage::newFromFile($path, ['access' => 'sequential']);

        if ($this->shouldCrop()) {
            $width = floor(abs($this->extent[0] - $this->extent[2]));
            $height = floor(abs($this->extent[1] - $this->extent[3]));

            $image = $image->crop($this->extent[0], $this->extent[3], $width, $height);
        }

        if ($this->shouldResize()) {
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
        }

        return $image->writeToBuffer(".{$format}", [
            'Q' => 85,
            'strip' => true,
        ]);
    }

    protected function shouldCrop()
    {
        return !($this->extent[0] == 0 && $this->extent[1] == $this->image->height && $this->extent[2] == $this->image->width && $this->extent[3] == 0);
    }

    protected function shouldResize()
    {
        $targetSize = config('magic_sam.sam_target_size');
        $width = $this->extent[2] - $this->extent[0];
        $height = $this->extent[1] - $this->extent[3];

        return max($width, $height) != $targetSize;
    }

}
