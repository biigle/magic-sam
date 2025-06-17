<?php

namespace Biigle\Modules\MagicSam\Jobs;

use Exception;
use FileCache;
use Biigle\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Jcupitt\Vips\Image as VipsImage;
use Biigle\Modules\MagicSam\Embedding;
use Illuminate\Support\Facades\Storage;

class GenerateEmbedding extends AbstractGenerateEmbedding
{

    /**
     * Coordinates of viewport's lower left and upper right corner.
     *
     * @var array
     */
    protected $extent;

    /**
     * Array containing the group, zoom level, column and row index for each tile.
     *
     * @var array
     */
    protected $tiles;

    /**
     * Coordinate of the lower left tile's lower left corner and the upper right tile's upper right corner.
     *
     * @var array
     */
    protected $tiledImageExtent;

    /**
     * Number of tiles used to cover the viewport horizontally.
     *
     * @var int
     */
    protected $tileColumns;

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
     * @param Request $request
     */
    public function __construct(Image $image, Request $request)
    {
        $this->image = $image;
        $this->user = $request->user();
        $this->extent = $request->input('extent');
        $this->tiles = $request->input('tiles', []);
        $this->tiledImageExtent = $request->input('tiledImageExtent', []);
        $this->tileColumns = $request->input('columns', 0);
    }

    /**
      * Process the job.
      *
      * @return void
      */
    public function process()
    {
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
            $disk->makeDirectory($prefix);
        }

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
    }

    /**
     * Generate the embedding.
     *
     * @param string $embeddingFilename File name of the embedding
     *
     * @return string embedding as binary
     */
    protected function generateEmbedding($embeddingFilename, $destPath)
    {
        return FileCache::getOnce($this->image, function ($file, $path) use ($embeddingFilename, $destPath) {
            // Crop and resize image
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

    /**
     * Generate an embedding for a tiled image.
     *
     * @param mixed $embeddingFilename File name of the embedding
     * @param mixed $destPath Path where emebdding will be saved
     * @throws \Exception if embedding couldn't be generated
     *
     * @return void
     */
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

    /**
     * Process an image by cropping and resizing.
     *
     * @param mixed $image Image that should be processed
     * @param mixed $path Cached image's path
     *
     * @return string Processed image
     */
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

    /**
     * Check whether image section should be extracted from image.
     *
     * @param mixed $image Original image
     *
     * @return bool false if extent describes whole image, otherwise true
     */
    protected function shouldCrop($image)
    {
        return !($this->extent[0] == 0 && $this->extent[1] == $image->height && $this->extent[2] == $image->width && $this->extent[3] == 0);
    }

    /**
     * Check whether image needs resizing.
     *
     * @param mixed $image (Cropped) image
     *
     * @return bool false if image's longest side equals the target size, otherwise true
     */
    protected function shouldResize($image)
    {
        $targetSize = config('magic_sam.sam_target_size');
        return max($image->width, $image->height) != $targetSize;
    }

    /**
     * Extract image section from image.
     *
     * @param VipsImage $image Image that should be used for extraction
     *
     * @return VipsImage The cropped image
     */
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

    /**
     * Resizes image to model's target size.
     *
     * @param VipsImage $image The image to resize
     *
     * @return VipsImage The resized image
     */
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

    /**
     * Create image from tiles and process it.
     *
     * @return string Image buffer
     */
    protected function createImageFromTiles()
    {
        $disk = Storage::disk(config('image.tiles.disk'));
        $fragment = fragment_uuid_path($this->image->uuid);
        $format = config('image.tiles.format');

        $vipsTiles = [];

        foreach ($this->tiles as $tile) {
            $group = $tile['group'];
            $filename = $tile['zoom'] . "-" . $tile['x'] . "-" . $tile['y'];
            $buffer = $disk->get("{$fragment}/TileGroup{$group}/{$filename}.{$format}");
            $vipsTiles[] = VipsImage::newFromBuffer(buffer: $buffer, options: ['access' => 'sequential']);
        }

        $image = VipsImage::arrayjoin($vipsTiles, ['across' => $this->tileColumns]);

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
