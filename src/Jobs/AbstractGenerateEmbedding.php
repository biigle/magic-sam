<?php

namespace Biigle\Modules\MagicSam\Jobs;

use Exception;
use Biigle\User;
use Biigle\Image;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Cache;
use Biigle\Modules\MagicSam\Embedding;
use Illuminate\Queue\SerializesModels;
use Biigle\Modules\MagicSam\Events\EmbeddingFailed;
use Biigle\Modules\MagicSam\Events\EmbeddingAvailable;

abstract class AbstractGenerateEmbedding
{
    use SerializesModels, Queueable;

    /**
     * The image to generate an embedding of.
     *
     * @var Image
     */
    protected $image;

    /**
     * The user who initiated the job.
     *
     * @var User
     */
    protected $user;

    /**
     * The embedding of the image (section)
     *
     * @var Embedding
     */
    protected $embedding;

    /**
     * Boolean that shows if job is processed asynchronously.
     *
     * @var bool
     */
    protected $isAsync = true;

    /**
     * Increment job counter in cache.
     *
     * @return void
     */
    protected function before()
    {
        $jobCount = config('magic_sam.job_count_cache_key');
        $userJobCount = sprintf(config('magic_sam.user_job_count'), $this->user->id);

        $this->incrementCacheCount($userJobCount);
        if ($this->isAsync) {
            $this->incrementCacheCount($jobCount);
        }
    }

    /**
     * Decrement job counter in cache.
     *
     * @return void
     */
    protected function after()
    {
        $jobCount = config('magic_sam.job_count_cache_key');
        $userJobCount = sprintf(config('magic_sam.user_job_count'), $this->user->id);

        $this->decrementCacheCount($userJobCount);
        if ($this->isAsync) {
            $this->decrementCacheCount($jobCount);
        }
    }

    public function process()
    {
        // will be overriden by GenerateEmbedding
    }

    /**
     * Handle embedding generation
     * 
     * @return void
     */
    public function handle()
    {
        $this->before();
        try {
            $this->process();
        } catch (Exception $e) {
            if ($this->isAsync) {
                EmbeddingFailed::dispatch($this->user);
            }
            $this->after();
            throw $e;
        }
        if ($this->isAsync) {
            $prefix = fragment_uuid_path($this->image->uuid);
            $e = $this->getEmbedding();
            $filenameHash = $e->getFilenameHash();
            $embFilename = "{$filenameHash}.npy";
            EmbeddingAvailable::dispatch($this->user, $e->id, "{$prefix}/{$embFilename}", $e->getExtent());
        }
        $this->after();
    }

    /**
     * Handle embedding generation synchronously
     * 
     * @return Embedding
     */
    public function handleSync()
    {
        $this->isAsync = false;
        $this->handle();
        return $this->getEmbedding();
    }

    /**
     * Decrement cache count
     *
     * @param int $cacheKey
     * 
     * @return void
     */
    protected function decrementCacheCount($cacheKey)
    {
        if (Cache::get($cacheKey) > 0) {
            Cache::decrement($cacheKey);
        } else {
            Cache::put($cacheKey, 0);
        }
    }

    protected function incrementCacheCount($key)
    {
        if (!Cache::has($key)) {
            Cache::add($key, 1);
        } else {
            Cache::increment($key);
        }
    }

    public function getEmbedding()
    {
        return $this->embedding;
    }
}