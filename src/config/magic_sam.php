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
    | Path to the Python executable.
    */
    'python' => env('MAGIC_SAM_PYTHON', '/usr/bin/python3'),

    /*
    | Path to the compute embedding script.
    */
    'compute_embedding_script' => __DIR__.'/../resources/scripts/compute_embedding.py',

    /*
    | The device to compute the SAM embedding on.
    |
    | Devices: cpu, cuda
    */
    'device' => env('MAGIC_SAM_DEVICE', 'cpu'),

    /*
    | URL from which to download the model checkpoint.
    |
    | Important: The model checkpoint mst match with the ONNX file (see below)!
    |
    | See: https://github.com/facebookresearch/segment-anything#model-checkpoints
    */
    'model_url' => env('MAGIC_SAM_MODEL_URL', 'https://dl.fbaipublicfiles.com/segment_anything/sam_vit_h_4b8939.pth'),

    /*
    | Model ONNX file.
    |
    | Important: The ONNX file must match with the model checkpoint (see above)!
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
     | Specifies the time in days after which an image embedding file will be deleted
     | again.
     */
    'prune_age_days' => env('MAGIC_SAM_PRUNE_AGE_DAYS', 30),

    /*
     | Threshold that determines when jobs should be queued rather than executed immediately
    */
    'queue_threshold' => env('MAGIC_SAM_QUEUE_THRESHOLD', 2),

    /*
     | Cache key for job count
    */
    'job_count_cache_key' => 'job_count',

    /*
    | Cache key for user job counts
   */
    'user_job_count' => 'embedding-generation-%u',

    /*
     | Factor that determines the image section size limit
    */
    'image_section_max_size_factor' => 0.31,

    /*
    | Lower size limit for images processed by magic-sam
    */
    'sam_target_size' => env('MAGIC_SAM_TARGET_SIZE', 1024),

];
