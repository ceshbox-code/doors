<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Core\ApiException;

/** Проверка фактического содержимого фотографии и создание оптимизированной копии (GD). */
final class ImageProcessor
{
    public const MAX_BYTES    = 15728640;      // 15 МБ (ТЗ 4.4)
    public const MAX_PIXELS   = 40000000;      // 40 Мп
    public const OPT_MAX_SIDE = 1600;
    public const OPT_QUALITY  = 82;
    public const TYPES        = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private static function invalid(string $message): ApiException
    {
        return new ApiException('PHOTO_INVALID', $message, 422);
    }

    /**
     * Не доверяет ни расширению, ни Content-Type клиента: тип определяется по содержимому (finfo),
     * затем сверяется с заголовком изображения (getimagesize).
     *
     * @return array{mime:string,ext:string,width:int,height:int,size:int}
     */
    public static function inspect(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            throw self::invalid('Файл пустой');
        }
        if ($size > self::MAX_BYTES) {
            throw new ApiException('PHOTO_TOO_LARGE', 'Размер фотографии не должен превышать 15 МБ', 413);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!isset(self::TYPES[$mime])) {
            throw self::invalid('Допустимы только фотографии JPEG, PNG и WebP');
        }
        $info = @getimagesize($path);
        if ($info === false || ($info['mime'] ?? '') !== $mime) {
            throw self::invalid('Содержимое файла не является корректным изображением');
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($w < 1 || $h < 1) {
            throw self::invalid('Некорректные размеры изображения');
        }
        if ($w * $h > self::MAX_PIXELS || $w * $h * 6 > self::memoryBudget()) {
            throw new ApiException('PHOTO_TOO_LARGE_DIMENSIONS', 'Слишком большое разрешение для обработки. Уменьшите разрешение камеры (до 40 Мп).', 422);
        }
        if ($mime === 'image/webp' && !(imagetypes() & IMG_WEBP)) {
            throw new ApiException('PHOTO_UNSUPPORTED', 'Сервер не поддерживает WebP. Используйте JPEG или PNG.', 415);
        }
        return ['mime' => $mime, 'ext' => self::TYPES[$mime], 'width' => $w, 'height' => $h, 'size' => (int) $size];
    }

    /** Сколько памяти можно потратить на декодирование (чтобы вместо фатальной ошибки PHP вернуть понятный отказ). */
    private static function memoryBudget(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $limit;
        switch (strtolower(substr($limit, -1))) {
            case 'g': $n *= 1024;
            // no break
            case 'm': $n *= 1024;
            // no break
            case 'k': $n *= 1024;
        }
        return max(0, (int) ($n * 0.8) - memory_get_usage(true));
    }

    /**
     * Операции для приведения фото к нормальной ориентации по EXIF (значения 1–8).
     * flip: 'h' — зеркало по горизонтали, 'v' — по вертикали; rotate_ccw — поворот против часовой, градусы (как в imagerotate).
     *
     * @return array{flip:?string,rotate_ccw:int}
     */
    public static function orientationOps(int $orientation): array
    {
        switch ($orientation) {
            case 2: return ['flip' => 'h',  'rotate_ccw' => 0];
            case 3: return ['flip' => null, 'rotate_ccw' => 180];
            case 4: return ['flip' => 'v',  'rotate_ccw' => 0];
            case 5: return ['flip' => 'h',  'rotate_ccw' => 90];
            case 6: return ['flip' => null, 'rotate_ccw' => 270];   // повернуть на 90° по часовой
            case 7: return ['flip' => 'h',  'rotate_ccw' => 270];
            case 8: return ['flip' => null, 'rotate_ccw' => 90];    // повернуть на 270° по часовой
            default: return ['flip' => null, 'rotate_ccw' => 0];
        }
    }

    /** @return array{width:int,height:int} размеры оптимизированной копии */
    public static function createOptimized(string $srcPath, string $mime, string $destPath): array
    {
        switch ($mime) {
            case 'image/jpeg': $src = @imagecreatefromjpeg($srcPath); break;
            case 'image/png':  $src = @imagecreatefrompng($srcPath);  break;
            case 'image/webp': $src = @imagecreatefromwebp($srcPath); break;
            default:           $src = false;
        }
        if ($src === false) {
            throw self::invalid('Файл повреждён или не является изображением');
        }

        // Сначала уменьшаем, потом поворачиваем: так не нужна вторая копия большого изображения в памяти
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, self::OPT_MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));     // прозрачность PNG/WebP -> белый фон
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($srcPath);
            $ops = self::orientationOps(is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1);
            if ($ops['flip'] !== null) {
                imageflip($dst, $ops['flip'] === 'h' ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
            }
            if ($ops['rotate_ccw'] !== 0) {
                $rot = imagerotate($dst, (float) $ops['rotate_ccw'], 0);
                if ($rot !== false) {
                    imagedestroy($dst);
                    $dst = $rot;
                }
            }
        }

        imageinterlace($dst, true);          // прогрессивный JPEG; повторное кодирование убирает EXIF/GPS из копии
        $ok = imagejpeg($dst, $destPath, self::OPT_QUALITY);
        $outW = imagesx($dst);
        $outH = imagesy($dst);
        imagedestroy($dst);
        if (!$ok) {
            throw new ApiException('PHOTO_PROCESSING_FAILED', 'Не удалось обработать фотографию', 500);
        }
        return ['width' => $outW, 'height' => $outH];
    }
}
