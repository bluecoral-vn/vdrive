<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File Content Max Bytes
    |--------------------------------------------------------------------------
    |
    | Maximum file size (in bytes) allowed for text content retrieval via
    | the GET /api/files/{file}/content endpoint. Default: 1MB.
    |
    */
    'content_max_bytes' => (int) env('FILE_CONTENT_MAX_BYTES', 1_048_576),

];
