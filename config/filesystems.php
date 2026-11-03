<?php

$s3 = [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => false,
    'report' => false,
];

/*
 * Where agencies' files live: "local" (this node's disk; tests and a single
 * node) or "s3" (one bucket every node shares, which is what lets more than
 * one node serve the app). Tenancy keeps each agency's files under its own
 * prefix either way (config/tenancy.php).
 */
$agencyFilesOnS3 = env('AGENCY_FILES_DRIVER', 'local') === 's3';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // An agency's public files: gallery media and its logo. On S3 no
        // per-object ACL is set (most buckets refuse ACLs); the bucket's own
        // policy makes the agency prefixes publicly readable.
        'public' => $agencyFilesOnS3 ? $s3 : [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Livewire's temporary uploads, which must be readable from whichever
        // node handles the next request. On S3 the driver is named
        // `s3-via-app` (AppServiceProvider): the same S3 adapter, but Livewire
        // only switches to direct browser-to-bucket uploads for a driver named
        // `s3`, which would need CORS and a bucket host the browser can reach.
        'uploads' => $agencyFilesOnS3 ? [...$s3, 'driver' => 's3-via-app'] : [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => false,
            'report' => false,
        ],

        's3' => $s3,

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
