<?php
declare(strict_types=1);

namespace App\Helpers;

/** Уменьшенная копия фото для встраивания в PDF (data URI): отчёт не раздувается и не зависит от доступа mPDF к файлам. */
final class PhotoThumb
{
    public static function dataUri(string $path, int $maxSide = 640, int $quality = 72): ?string
    {
        $info = @getimagesize($path);
        if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_JPEG) {
            return null;
        }
        $src = @imagecreatefromjpeg($path);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        ob_start();
        imagejpeg($dst, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($dst);
        return $bytes === '' ? null : 'data:image/jpeg;base64,' . base64_encode($bytes);
    }
}
