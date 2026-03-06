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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Jcupitt\Vips\Image as VipsImage;

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

        $inputSize = config('magic_sam.model_input_size');
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
