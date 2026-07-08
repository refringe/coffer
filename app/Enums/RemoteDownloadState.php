<?php

declare(strict_types=1);

namespace App\Enums;

enum RemoteDownloadState: string
{
    case Queued = 'queued';
    case Downloading = 'downloading';
    case Completed = 'completed';
    case Failed = 'failed';
}
