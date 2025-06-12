<?php

namespace Biigle\Modules\MagicSam\Http\Requests;

use Biigle\Image;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmbedding extends FormRequest
{

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        $this->image = Image::find($this->route('id'));
        return $this->user()->can('access', $this->image);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'extent' => 'required|list|size:4',
            'extent.*' => 'numeric|gte:0',
            'excludeEmbeddingId' => 'nullable|int|gt:0',
            'tiles' => 'nullable|array',
            'tiles.*' => 'array|size:4',
            'tiles.*.*' => 'int|gte:0',
            'tiledImageExtent' => 'nullable|list|size:4',
            'tiledImageExtent.*' => 'numeric|gte:0',
            'columns' => 'nullable|int|gte:1'
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $targetSize = config('magic_sam.sam_target_size');
            $extent = $this->input('extent');

            $width = abs($extent[2] - $extent[0]);
            $height = abs($extent[1] - $extent[3]);

            if ($width < $targetSize || $height < $targetSize) {
                $validator->errors()->add('extent', "The image's width and height need to be greater or equal than {$targetSize} pixel.");
            }

            if ($this->image->tiled) {
                $tiles = $this->input('tiles', []);
                $tiledImageExtent = $this->input('tiles', []);

                if (!$tiles) {
                    $validator->errors()->add('tile', 'The tiled image needs to provide the tile groups, zoom level, column and row indices.');
                }

                if (!$tiledImageExtent) {
                    $validator->errors()->add('tiledImageExtent', 'The tiled image needs to provide the extent of the tiled area');
                }
            }

        });
    }
}