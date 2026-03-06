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
     * The bbox of the image (x, y, width, height) or null for full image.
     *
     * @var array|null
     */
    public $bbox;

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
     * @param array|null $bbox Optional bbox (x, y, width, height)
     * @return void
     */
    public function __construct($filename, User $user, ?array $bbox = null)
    {
        $this->filename = $filename;
        $this->user = $user;
        $this->bbox = $bbox;
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

        $data = ['url' => $url];

        if ($this->bbox !== null) {
            $data['bbox'] = $this->bbox;
        }

        return $data;
    }
}
