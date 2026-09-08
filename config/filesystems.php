<?php

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

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * The one file this product accepts from anybody: the document an
         * employee attaches to a leave request.
         *
         * It lives under storage/, which is a directory above the document
         * root, so the web server has no path to it and PHP-FPM is never
         * asked to execute anything in it - a file whose name ends .pdf and
         * whose bytes are a script is therefore inert here in a way it
         * could never be under public/. There is deliberately no 'url' and
         * no 'serve': a stored path must never be a capability, so the only
         * way to these bytes is the leave-attachments.show route, which
         * asks LeaveRequestPolicy the same question the admin resource
         * asks before it streams anything.
         *
         * 'throw' is true because this disk is read on a request that a
         * person is waiting on. A file the server cannot read is a fault to
         * find in the log - permissions after a deployment, most likely -
         * and never an empty download that looks like an empty document.
         */
        'leave-attachments' => [
            'driver' => 'local',
            'root' => storage_path('app/private/leave-attachments'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        's3' => [
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
        ],

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
