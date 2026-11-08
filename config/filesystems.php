<?php

$firebaseCredentialsBase64 = env('FIREBASE_STORAGE_CREDENTIALS_BASE64');
$firebaseCredentials = null;

if (is_string($firebaseCredentialsBase64) && $firebaseCredentialsBase64 !== '') {
    $decodedCredentials = base64_decode($firebaseCredentialsBase64, true);
    $parsedCredentials = $decodedCredentials === false
        ? null
        : json_decode($decodedCredentials, true);

    if (!is_array($parsedCredentials)
        || ($parsedCredentials['type'] ?? null) !== 'service_account'
        || empty($parsedCredentials['client_email'])
        || empty($parsedCredentials['private_key'])) {
        throw new RuntimeException('FIREBASE_STORAGE_CREDENTIALS_BASE64 must contain a base64-encoded service account JSON file.');
    }

    $firebaseCredentials = $parsedCredentials;
}

$publicStorageDriver = env('PUBLIC_STORAGE_DRIVER', 'local');

if ($publicStorageDriver === 'gcs') {
    if ($firebaseCredentials === null) {
        throw new RuntimeException('Firebase Storage is enabled but FIREBASE_STORAGE_CREDENTIALS_BASE64 is not configured.');
    }

    if (!env('FIREBASE_STORAGE_PROJECT_ID') || !env('FIREBASE_STORAGE_BUCKET')) {
        throw new RuntimeException('Firebase Storage requires FIREBASE_STORAGE_PROJECT_ID and FIREBASE_STORAGE_BUCKET.');
    }
}

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
    | Supported drivers: "local", "ftp", "sftp", "s3", "gcs"
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
            'driver' => $publicStorageDriver,
            'root' => $publicStorageDriver === 'gcs' ? '' : storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => $publicStorageDriver === 'gcs' ? 'private' : 'public',
            'throw' => $publicStorageDriver === 'gcs',
            'report' => false,
            'key_file' => $firebaseCredentials,
            'project_id' => env('FIREBASE_STORAGE_PROJECT_ID'),
            'bucket' => env('FIREBASE_STORAGE_BUCKET'),
            'visibility_handler' => $publicStorageDriver === 'gcs'
                ? \League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility::class
                : null,
            'signed_url_minutes' => (int) env('FIREBASE_STORAGE_SIGNED_URL_MINUTES', 60),
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
