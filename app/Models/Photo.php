<?php
declare(strict_types=1);

namespace App\Models;

use App\Helpers\Dates;

final class Photo
{
    public static function present(array $r): array
    {
        $id = (int) $r['id'];
        return [
            'id'              => $id,
            'work_record_id'  => (int) $r['work_record_id'],
            'original_name'   => $r['original_name'],
            'mime_type'       => $r['mime_type'],
            'file_size'       => (int) $r['file_size'],
            'sha256'          => $r['sha256'],
            'created_at'      => Dates::toIso($r['created_at']),
            'url'             => '/api/v1/photos/' . $id,
            'original_url'    => '/api/v1/photos/' . $id . '?variant=original',
        ];
    }
}
