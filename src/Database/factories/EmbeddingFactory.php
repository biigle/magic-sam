<?php

namespace Biigle\Modules\MagicSam\Database\factories;

use Biigle\Image;
use Biigle\Modules\MagicSam\Embedding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\Biigle\Model>
 */
class EmbeddingFactory extends Factory
{
    protected $model = Embedding::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filename' => $this->faker->unique()->word(),
            'image_id' => Image::factory(),
            'x' => 0,
            'y' => 0,
            'x2' => 100,
            'y2' => 100,
        ];
    }
}
