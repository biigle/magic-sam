<?php

use Biigle\Modules\MagicSam\Http\Middleware\TrackUserJobs;

$router->post('api/v1/images/{id}/sam-embedding', [
   'middleware' => ['api', 'auth:web,api', TrackUserJobs::class],
   'uses' => 'ImageEmbeddingController@store',
]);
