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
        $image = Image::find($this->route('id'));
        return $this->user()->can('access', $image);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'extent' => 'required|array|size:4',
            'extent.*' => 'numeric|gte:0',
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

            $width = $extent[2] - $extent[0];
            $height = $extent[1] - $extent[3];

            if ($width < $targetSize || $height < $targetSize) {
                $validator->errors()->add('extent', "The image's width and height need to be greater or equal than {$targetSize} pixel.");
            }
        });
    }
}