<?php

namespace Biigle\Modules\MagicSam\Events;

use Biigle\Broadcasting\UserChannel;
use Biigle\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class EmbeddingAvailable implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The user that requested the embedding.
     *
     * @var User
     */
    public $user;

    /**
     * The embedding filename on the storage disk.
     *
     * @var string
     */
    public $filename;

    /**
     * The viewport's extent of the embedding
     * @var array
     */
    public $extent;

    /**
     * Id of the embedding
     *
     * @var int
     */
    public $embeddingId;

    /**
     * The name of the queue the job should be sent to.
     *
     * @var string|null
     */
    public $queue;

    /**
     * Ignore this job if the image or user does not exist any more.
     *
     * @var bool
     */
    protected $deleteWhenMissingModels = true;

    /**
     * Create a new event instance.
     *
     * @param string $filename
     * @param User $user
     * @return void
     */
    public function __construct(User $user, int $embeddingId, string $filename, array $extent)
    {
        $this->filename = $filename;
        $this->user = $user;
        $this->extent = $extent;
        $this->embeddingId = $embeddingId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new UserChannel($this->user);
    }

    /**
     * Get the data to broadcast.
     *
     * @return array
     */
    public function broadcastWith()
    {
        $disk = Storage::disk(config('magic_sam.embedding_storage_disk'));

        if ($disk->providesTemporaryUrls()) {
            $url = $disk->temporaryUrl($this->filename, now()->addHour());
        } else {
            $url = $disk->url($this->filename);
        }

        return [
            'id' => $this->embeddingId,
            'url' => $url,
            'extent' => $this->extent,
        ];
    }
}
