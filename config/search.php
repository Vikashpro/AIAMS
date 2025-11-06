<?php

return [
    'host' => env('ELASTICSEARCH_HOST'),
    'index' => env('ELASTICSEARCH_INDEX', 'aiams_documents'),
    'username' => env('ELASTICSEARCH_USERNAME'),
    'password' => env('ELASTICSEARCH_PASSWORD'),
    'timeout' => (int) env('ELASTICSEARCH_TIMEOUT', 5),
];
