<?php

namespace Biigle\Modules\MagicSam;

use Biigle\Image;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Biigle\Modules\MagicSam\Database\factories\EmbeddingFactory;

/**
 * This model stores information of an SAM embedding
 */
class Embedding extends Model
{
    use HasFactory;

    /**
     * The attributes that should be casted to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'x2' => 'float',
        'y2' => 'float',
        'created_at' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'image_id',
        'filename',
        'x',
        'y',
        'x2',
        'y2',
        'created_at',
    ];

    public function image()
    {
        return $this->belongsTo(Image::class, 'image_id');
    }

    public function getFile()
    {
        $prefix = fragment_uuid_path($this->image()->first()->uuid);
        return Storage::disk(config('magic_sam.embedding_storage_disk'))->get("{$prefix}/{$this->filename}");
    }

    public function getExtent()
    {
        return [$this->x, $this->y, $this->x2, $this->y2];
    }

    public function getFilenameHash()
    {
        return md5(serialize([$this->image_id,...$this->getExtent()]));
    }

    public function getWidth(){
        return abs($this->x2-$this->x);
    }

    public function getHeight(){
        return abs($this->y2-$this->y);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    protected static function newFactory()
    {
        return EmbeddingFactory::new();
    }

    public static function getNearestEmbedding($imgId, $extent, $embId = 0)
    {
        $sizeFactor = config('magic_sam.image_section_max_size_factor');
        $width = abs($extent[2] - $extent[0]);
        $height = abs($extent[3] - $extent[1]);

        $maxWidth = $width * (1 + $sizeFactor);
        $maxHeight = $height * (1 + $sizeFactor);

        $minX = $extent[0] * (1 - $sizeFactor);
        $minY = $extent[1] * (1 - $sizeFactor);

        return self::where('image_id', '=', $imgId)
            ->when($embId, fn($query) => $query->where('id', '!=', $embId)) // only for refinement step
            ->whereRaw("x = ? and y = ? and x2 = ? and y2 = ?", [$extent])
            ->orWhereRaw("abs(x2-x) < ?", [$maxWidth])
            ->whereRaw("abs(y2-y) < ?", [$maxHeight])
            ->whereRaw("x > ? and x <= ?", [$minX, $extent[0]])
            ->whereRaw("y > ? and y <= ?", [$minY, $extent[1]])
            ->first(); // TODO: Look for emb whose center is nearest to the current embedding's center
    }

}