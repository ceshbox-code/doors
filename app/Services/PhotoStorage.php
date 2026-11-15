<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Logger;
use App\Helpers\ImageProcessor;

/**
 * Файловое хранилище фотографий вне публичного каталога (storage/photos/ГГГГ/ММ/).
 * Оригинал хранится как есть под случайным серверным именем, рядом — оптимизированная копия.
 */
final class PhotoStorage
{
    public const MAX_PER_REQUEST = 10;
    public const MAX_PER_RECORD  = 20;

    private string $base;

    public function __construct(?string $base = null)
    {
        $this->base = rtrim($base ?? (Config::get('paths.storage') . '/photos'), '/');
    }

    /** Безопасное имя для показа: без путей и управляющих символов, до 255 символов. */
    public static function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            return 'photo';
        }
        return mb_substr($name, 0, 255);
    }

    /**
     * Проверка факта и размера загрузки (дёшево): ошибки PHP, is_uploaded_file, SHA-256.
     *
     * @param array<int,array{name:string,tmp_name:string,size:int,error:int}> $uploads
     * @return array<int,array{upload:array,sha256:string}>
     */
    public function prepare(array $uploads): array
    {
        if (count($uploads) > self::MAX_PER_REQUEST) {
            throw new ApiException('TOO_MANY_PHOTOS', 'За один запрос можно загрузить не более ' . self::MAX_PER_REQUEST . ' фотографий', 422);
        }
        $out = [];
        $seen = [];
        foreach ($uploads as $u) {
            switch ($u['error']) {
                case UPLOAD_ERR_OK:
                    break;
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    throw new ApiException('PHOTO_TOO_LARGE', 'Размер фотографии не должен превышать 15 МБ', 413);
                case UPLOAD_ERR_PARTIAL:
                    throw new ApiException('PHOTO_UPLOAD_FAILED', 'Фотография загружена не полностью. Повторите отправку.', 400);
                default:
                    Logger::error('Ошибка загрузки файла PHP', ['code' => $u['error']]);
                    throw new ApiException('PHOTO_UPLOAD_FAILED', 'Не удалось принять фотографию на сервере', 500);
            }
            if (!is_uploaded_file($u['tmp_name'])) {
                throw new ApiException('PHOTO_UPLOAD_FAILED', 'Некорректная загрузка файла', 400);
            }
            $sha = hash_file('sha256', $u['tmp_name']);
            if ($sha === false) {
                throw new ApiException('PHOTO_UPLOAD_FAILED', 'Не удалось прочитать загруженный файл', 500);
            }
            if (isset($seen[$sha])) {
                throw new ApiException('DUPLICATE_PHOTO', 'Одна и та же фотография приложена дважды', 422);
            }
            $seen[$sha] = true;
            $out[] = ['upload' => $u, 'sha256' => $sha];
        }
        return $out;
    }

    /**
     * Полная проверка содержимого и создание оптимизированной копии во временный файл.
     * При ошибке уже созданные временные копии удаляются.
     *
     * @param array<int,array{upload:array,sha256:string}> $prepared
     * @return array<int,array<string,mixed>>
     */
    public function stage(array $prepared): array
    {
        $staged = [];
        try {
            foreach ($prepared as $p) {
                $tmp = $p['upload']['tmp_name'];
                $info = ImageProcessor::inspect($tmp);
                $opt = (string) tempnam(sys_get_temp_dir(), 'door_opt_');
                try {
                    $dim = ImageProcessor::createOptimized($tmp, $info['mime'], $opt);
                } catch (\Throwable $e) {
                    @unlink($opt);
                    throw $e;
                }
                $staged[] = [
                    'original_tmp'  => $tmp,
                    'optimized_tmp' => $opt,
                    'original_name' => self::sanitizeName($p['upload']['name']),
                    'mime'          => $info['mime'],
                    'ext'           => $info['ext'],
                    'size'          => $info['size'],
                    'width'         => $info['width'],
                    'height'        => $info['height'],
                    'sha256'        => $p['sha256'],
                ];
            }
        } catch (\Throwable $e) {
            $this->discardStaged($staged);
            throw $e;
        }
        return $staged;
    }

    /**
     * Копирует подготовленные файлы на постоянное место. Вызывается внутри транзакции БД;
     * при любой ошибке уже созданные файлы удаляются.
     *
     * @return array<int,array<string,mixed>> staged + file_name, optimized_file_name (относительные пути)
     */
    public function place(array $staged): array
    {
        $sub = gmdate('Y/m');
        $dir = $this->base . '/' . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог фотографий');
        }
        $placed = [];
        try {
            foreach ($staged as $s) {
                $id = bin2hex(random_bytes(16));
                $orig = "{$sub}/{$id}.{$s['ext']}";
                $opt  = "{$sub}/{$id}_opt.jpg";
                $entry = $s + ['file_name' => $orig, 'optimized_file_name' => $opt];
                $placed[] = $entry;                       // раньше копирования: частичные файлы тоже будут удалены
                if (!copy($s['original_tmp'], $this->base . '/' . $orig) || !copy($s['optimized_tmp'], $this->base . '/' . $opt)) {
                    throw new \RuntimeException('Не удалось сохранить файл фотографии');
                }
                @chmod($this->base . '/' . $orig, 0640);
                @chmod($this->base . '/' . $opt, 0640);
                if (hash_file('sha256', $this->base . '/' . $orig) !== $s['sha256']) {
                    throw new \RuntimeException('Контрольная сумма сохранённого файла не совпала');
                }
            }
        } catch (\Throwable $e) {
            $this->discard($placed);
            throw $e;
        }
        return $placed;
    }

    /** Удаляет файлы, созданные place() (откат). */
    public function discard(array $placed): void
    {
        foreach ($placed as $p) {
            foreach (['file_name', 'optimized_file_name'] as $k) {
                if (!empty($p[$k])) {
                    @unlink($this->base . '/' . $p[$k]);
                }
            }
        }
    }

    /** Удаляет временные оптимизированные копии. */
    public function discardStaged(array $staged): void
    {
        foreach ($staged as $s) {
            if (!empty($s['optimized_tmp'])) {
                @unlink($s['optimized_tmp']);
            }
        }
    }

    /** Абсолютный путь к файлу по относительному из БД; null, если путь небезопасен или файла нет. */
    public function resolve(string $relative): ?string
    {
        if ($relative === '' || strpbrk($relative, "\0\\") !== false || $relative[0] === '/' || str_contains($relative, '..')) {
            return null;
        }
        $root = realpath($this->base);
        $path = realpath($this->base . '/' . $relative);
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }
        return $path;
    }
}
