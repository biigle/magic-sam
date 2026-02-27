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
     */
    public function __construct(Image $image, User $user)
    {
        $this->image = $image;
        $this->user = $user;
    }

    /**
      * Handle the job.
      *
      * @return void
      */
    public function handle()
    {
        $filename = "{$this->image->id}.npy";
        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));
        try {
            if (!$disk->exists($filename)) {
                $embedding = $this->generateEmbedding($this->image);
                $disk->put($filename, $embedding);
            }

            EmbeddingAvailable::dispatch($filename, $this->user);
        } catch (Exception $e) {
            EmbeddingFailed::dispatch($this->user);
            throw $e;
        }
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

        $inputSize = config('magic_sam.model_input_size');
        $factor = $inputSize / max($image->width, $image->height);
        $image = $image->resize($factor);

        return $image->writeToBuffer('.png');
    }

    /**
     * Send the scaled-down PNG image to the Python worker and return the embedding npy
     * file as binary blob.
     */
    protected function sendPyworkerRequest(string $buffer): string
    {
        $url = config('magic_sam.generate_embedding_worker_url');
        $response = Http::withBody($buffer, 'image/png')->post($url);
        if ($response->successful()) {
            return $response->body();
        } else {
            $pyException = $response->body();
            throw new Exception("Error in pyworker:\n {$pyException}");
        }
    }
}
