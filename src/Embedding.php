<?php

namespace Biigle\Modules\MagicSam;

use Biigle\Image;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * This model stores information of an SAM embedding
 */
class Embedding extends Model
{
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

}