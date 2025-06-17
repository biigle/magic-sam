<?php

$router->post('api/v1/images/{id}/sam-embedding', [
   'middleware' => ['api', 'auth:web,api'],
   'uses' => 'ImageEmbeddingController@store',
]);
