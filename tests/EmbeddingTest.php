<?php
namespace Biigle\Tests\Modules\MagicSam;

use ModelTestCase;
use Biigle\Modules\MagicSam\Embedding;

class EmbeddingTest extends ModelTestCase
{
    protected static $modelClass = Embedding::class;

    public function testAttributes()
    {
        $this->assertNotNull($this->model->filename);
        $this->assertNotNull($this->model->image_id);
        $this->assertNotNull($this->model->x);
        $this->assertNotNull($this->model->y);
        $this->assertNotNull($this->model->x2);
        $this->assertNotNull($this->model->y2);
    }

    public function testGetNearestEmbedding()
    {
        // Test for embedding that is too large
        self::create(['x' => 100, 'y' => 100, 'x2' => 300, 'y2' => 300]);
        // Test for embedding that does not match given image section
        self::create(['x' => 0, 'y' => 0, 'x2' => 100, 'y2' => 100]);
        // Reference embedding of image section
        $refEmb = self::create(['x' => 100, 'y' => 100, 'x2' => 200, 'y2' => 200]);
        $refEmb = $refEmb->fresh();

        $emb = Embedding::getNearestEmbedding($refEmb->image_id, [100, 100, 200, 200]);
        $this->assertSame($refEmb->toArray(), $emb->toArray());

        // Look for slightly bigger viewport (< +10% in size)

        // Overlap from lower left corner
        $emb = Embedding::getNearestEmbedding($refEmb->image_id, [105, 105, 200, 200]);
        $this->assertSame($refEmb->toArray(), $emb->toArray());

        // Overlap from lower right corner
        $emb = Embedding::getNearestEmbedding($refEmb->image_id, [105, 100, 200, 195]);
        $this->assertSame($refEmb->toArray(), $emb->toArray());

        // Overlap from upper right corner
        $emb = Embedding::getNearestEmbedding($refEmb->image_id, [100, 100, 195, 195]);
        $this->assertSame($refEmb->toArray(), $emb->toArray());

        // Overlap from upper left corner
        $emb = Embedding::getNearestEmbedding($refEmb->image_id, [100, 105, 195, 200]);
        $this->assertSame($refEmb->toArray(), $emb->toArray());

        // Given viewport is too small
        $emb = Embedding::getNearestEmbedding($refEmb->image_id, [110, 105, 200, 200]);
        $this->assertEmpty($emb);

        // Given viewport has no overlapping embedding
        $emb = Embedding::getNearestEmbedding($refEmb->image_id, [90, 90, 200, 200]);
        $this->assertEmpty($emb);
    }
}