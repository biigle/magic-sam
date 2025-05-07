<?php

namespace Biigle\Modules\MagicSam\Jobs;

use Exception;
use FileCache;
use Biigle\User;
use Biigle\Image;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
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
    public function __construct(Image $image, User $user, bool $isAsync = True)
    {
        $this->image = $image;
        $this->user = $user;
        $this->isAsync = $isAsync;
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

        $embeddingFilename = "{$this->image->id}.npy";
        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));
        try {
            $this->generateEmbedding($embeddingFilename, $disk->path($embeddingFilename));

            if ($this->isAsync) {
                $this->decrementJobCacheCount();
                EmbeddingAvailable::dispatch($embeddingFilename, $this->user);
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
            // Contact the pyworker to generate the embedding
            $response = Http::withOptions([
                'sink' => $destPath // stream response body to disk
            ])
                ->attach(
                    'image',
                    File::get($path),
                    $file->filename
                )
                ->post('http://pyworker:8080/embedding', ['filename' => $embeddingFilename]);

            if (!$response->successful()) {
                throw new Exception("The image couldn't be processed by the Magic-Sam tool. Please try again.");
            }
            return;
        });
    }
}
