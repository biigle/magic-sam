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
     * The validated bbox.
     *
     * @var array|null
     */
    protected $bbox;

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
        if ($this->hasBbox()) {
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

            // Tiled images require a bbox.
            if ($this->image->tiled && !$this->hasBbox()) {
                $validator->errors()->add('bbox', 'Bbox is required for tiled images.');
                return;
            }

            if (!$this->hasBbox()) {
                return;
            }

            $bbox = [
                'x' => (int) $this->input('x'),
                'y' => (int) $this->input('y'),
                'width' => (int) $this->input('width'),
                'height' => (int) $this->input('height'),
            ];

            // Validate bbox is fully within image bounds.
            if (
                $bbox['x'] >= $this->image->width ||
                $bbox['y'] >= $this->image->height ||
                $bbox['x'] + $bbox['width'] > $this->image->width ||
                $bbox['y'] + $bbox['height'] > $this->image->height
            ) {
                $validator->errors()->add('bbox', 'Bbox is outside image bounds.');
            } else {
                $this->bbox = $bbox;
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
     * Get the validated bbox.
     */
    public function getBbox(): ?array
    {
        return $this->bbox;
    }

    /**
     * Determine whether the request includes a bbox.
     *
     * @return boolean
     */
    public function hasBbox(): bool
    {
        return $this->has('x') || $this->has('y') || $this->has('width') || $this->has('height');
    }
}
