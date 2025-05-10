<?php
namespace Biigle\Tests\Modules\MagicSam;

use ModelTestCase;

class EmbeddingTest extends ModelTestCase
{
    protected static $modelClass = \Biigle\Modules\MagicSam\Embedding::class;

    public function testAttributes()
    {
        $this->assertNotNull($this->model->filename);
        $this->assertNotNull($this->model->image_id);
        $this->assertNotNull($this->model->x);
        $this->assertNotNull($this->model->y);
        $this->assertNotNull($this->model->x2);
        $this->assertNotNull($this->model->y2);
    }
}