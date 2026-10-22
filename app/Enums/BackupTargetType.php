<?php

declare(strict_types=1);

namespace App\Enums;

enum BackupTargetType: string
{
    case SiteLocal = 'site_local';
    case Sftp = 'sftp';
    case Scp = 'scp';
    case S3 = 's3';
    case Dropbox = 'dropbox';
    case Rclone = 'rclone';
}
