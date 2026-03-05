<?php

namespace Biigle\Modules\MagicSam\Http\Requests;

use Biigle\Image;
use Illuminate\Foundation\Http\FormRequest;

class StoreImageEmbeddingRequest extends FormRequest
{
    /**
     * The image instance.
     *
     * @var Image
     */
    protected $image;

    /**
     * The validated and expanded extent.
     *
     * @var array|null
     */
    protected $extent;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $this->image = Image::findOrFail($this->route('id'));

        return $this->user()->can('add-annotation', $this->image);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        if ($this->hasExtent()) {
            return [
                'x' => 'required|integer|min:0',
                'y' => 'required|integer|min:0',
                'width' => 'required|integer|min:1',
                'height' => 'required|integer|min:1',
            ];
        }

        return [];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->failed()) {
                return;
            }

            if (!$this->hasExtent()) {
                return;
            }

            $extent = [
                'x' => (int) $this->input('x'),
                'y' => (int) $this->input('y'),
                'width' => (int) $this->input('width'),
                'height' => (int) $this->input('height'),
            ];

            // Validate extent is fully within image bounds
            if (
                $extent['x'] >= $this->image->width ||
                $extent['y'] >= $this->image->height ||
                $extent['x'] + $extent['width'] > $this->image->width ||
                $extent['y'] + $extent['height'] > $this->image->height
            ) {
                $validator->errors()->add('extent', 'Extent is outside image bounds.');
            } else {
                $this->extent = $extent;
            }
        });
    }

    /**
     * Get the image instance.
     */
    public function getImage(): Image
    {
        return $this->image;
    }

    /**
     * Get the validated and expanded extent.
     */
    public function getExtent(): ?array
    {
        return $this->extent;
    }

    /**
     * Determine wether the request includes an extent.
     *
     * @return boolean
     */
    public function hasExtent(): bool
    {
        return $this->has('x') || $this->has('y') || $this->has('width') || $this->has('height');
    }
}
