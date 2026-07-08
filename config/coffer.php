<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum File Size
    |--------------------------------------------------------------------------
    |
    | The largest single file, in bytes, that may be uploaded to a share. A
    | value of 0 (or empty) disables the limit and relies on the operator to
    | size the underlying disk. Enforced when the file is uploaded (note that
    | PHP/proxy upload limits also apply, since uploads stream through the app).
    |
    */

    'max_file_size' => (int) env('COFFER_MAX_FILE_SIZE', 0),

    /*
    |--------------------------------------------------------------------------
    | Upload Chunk Size
    |--------------------------------------------------------------------------
    |
    | The size, in bytes, of each chunk the browser uploader sends while transferring a file (90 MB). Every chunk must
    | fit inside a single HTTP request, so this value has to stay below PHP's post_max_size and any request-body limit
    | enforced by a proxy in front of the app; the default sits under Cloudflare's 100 MB request-body cap.
    |
    */

    'upload_chunk_size' => 94371840,

    /*
    |--------------------------------------------------------------------------
    | Storage Base Directory
    |--------------------------------------------------------------------------
    |
    | The base directory new shares default their storage path under. Each share
    | stores its files in its own directory on this server; an administrator may
    | point a share at any absolute path (e.g. a mounted volume). In Docker this
    | is the /data/shares volume; locally it falls back under storage/app.
    |
    */

    'storage_path' => (string) env('COFFER_STORAGE_PATH', storage_path('app/shares')),

    /*
    |--------------------------------------------------------------------------
    | Recycle Bin Retention
    |--------------------------------------------------------------------------
    |
    | How many days a deleted file or folder stays in the recycle bin (the
    | share's hidden .trash directory) before the scheduled purge permanently
    | removes it from disk. Until then it can be restored. A value of 0 (or
    | empty) disables the purge and keeps recycle-bin items indefinitely.
    |
    */

    'trash_days' => (int) env('COFFER_TRASH_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Zip Download Retention
    |--------------------------------------------------------------------------
    |
    | How many hours a generated multi-file/folder zip archive is kept under
    | the share's hidden temporary directory before the scheduled sweep removes
    | it. The download link is short-lived; this only bounds leftover bytes. A
    | value of 0 (or empty) disables the sweep and keeps archives indefinitely.
    |
    */

    'zip_ttl_hours' => (int) env('COFFER_ZIP_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Stalled Upload Retention
    |--------------------------------------------------------------------------
    |
    | How many hours an in-progress upload may sit idle (no chunk received) before the scheduled purge removes its
    | partial file. Idle time is measured from the last received chunk, so a slow multi-day upload survives as long
    | as it keeps moving. A value of 0 (or empty) disables the purge and keeps partial uploads indefinitely.
    |
    */

    'upload_ttl_hours' => (int) env('COFFER_UPLOAD_TTL_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Activity Log Retention
    |--------------------------------------------------------------------------
    |
    | How many days a share activity record is kept before the scheduled purge
    | removes it, bounding the otherwise unbounded growth of the activity feed.
    | A value of 0 (or empty) disables the purge and keeps history indefinitely.
    |
    */

    'activity_days' => (int) env('COFFER_ACTIVITY_DAYS', 90),

];
