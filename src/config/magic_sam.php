<?php

return [
    /*
    | Storage disk where the SAM embeddings of images are stored.
    */
    'embedding_storage_disk' => env('MAGIC_SAM_EMBEDDING_STORAGE_DISK'),

    /*
    | Queue to submit jobs to compute embeddings to.
    */
    'request_queue' => env('MAGIC_SAM_REQUEST_QUEUE', 'default'),

    /*
    | Queue connection to submit new MAIA jobs to.
    */
    'request_connection' => env('MAGIC_SAM_REQUEST_CONNECTION', env('QUEUE_CONNECTION', env('QUEUE_DRIVER', 'sync'))),

    /*
    | Model ONNX file.
    |
    | Important: The ONNX file must match with the model type and checkpoint (see Docker
    | Compose configuration)!
    |
    | Available files are:
    |    - sam_vit_h_4b8939.quantized.onnx
    |    - sam_vit_l_0b3195.quantized.onnx
    |    - sam_vit_b_01ec64.quantized.onnx
    |
    | If you provide your own ONNX, place it in "public/vendor/magic-sam/".
    |
    | Example command to generate an ONNX (in the segment-anything repo):
    | python scripts/export_onnx_model.py \
    |   --checkpoint sam_vit_l_0b3195.pth \
    |   --model-type vit_l \
    |   --use-single-mask \
    |   --output sam_vit_l_0b3195.onnx \
    |   --quantize-out sam_vit_l_0b3195.quantized.onnx
    |
    | It is important to observe the onnx opset compatibility. The ONNX files provided
    | in this package were generated with onnx==1.13.1 and onnxruntime==1.14.0 to be
    | loaded with onnxruntime-web==1.14.0.
    |
    | See: https://github.com/facebookresearch/segment-anything#onnx-export
    | See: https://github.com/biigle/core/issues/580#issuecomment-1562458609
    | See: https://onnxruntime.ai/docs/reference/compatibility.html#onnx-opset-support
    */
    'onnx_file' => env('MAGIC_SAM_ONNX_FILE', 'sam_vit_h_4b8939.quantized.onnx'),


    /*
    | The SAM model type.
    |
    | See: https://github.com/facebookresearch/segment-anything#model-checkpoints
    */
    'model_type' => env('MAGIC_SAM_MODEL_TYPE', 'vit_h'),

    /*
    | Path to store the model checkpoint to.
    */
    'model_path' => storage_path('magic_sam').'/sam_checkpoint.pth',

    /*
     | Size in pixels of the longest edge that the SAM model expects of input images.
     */
    'model_input_size' => env('MAGIC_SAM_MODEL_INPUT_SIZE', 1024),

    /*
     | Specifies the time in days after which an image embedding file will be deleted
     | again.
     */
    'prune_age_days' => env('MAGIC_SAM_PRUNE_AGE_DAYS', 30),

    /*
    | URL to the GenerateEmbeddingWorker service. This should be set to the name of the
    | Docker Compose service running this script.
    */
    'generate_embedding_worker_url' => env('MAGIC_SAM_GENERATE_EMBEDDING_WORKER_URL', 'http://magic-sam-pyworker'),

    /*
    | Request timeout in seconds for the GenerateEmbeddingWorker service.
    */
    'worker_timeout' => env('MAGIC_SAM_WORKER_TIMEOUT', 60),

];
