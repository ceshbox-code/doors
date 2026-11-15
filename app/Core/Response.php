<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public int $status;
    public array $body;
    /** @var array<string,string> */
    public array $headers = [];
    /** Если задан — вместо JSON отдаётся этот файл (фотографии). */
    public ?string $filePath = null;
    /** Готовое содержимое файла для скачивания (отчёты PDF/CSV/XLSX). */
    public ?string $rawBody = null;

    public function __construct(int $status, array $body)
    {
        $this->status = $status;
        $this->body   = $body;
    }

    public static function ok($data = null, int $status = 200): self
    {
        return new self($status, ['success' => true, 'data' => $data]);
    }

    /** Единый формат ошибок из ТЗ, п. 12.11 (details — необязательное расширение для ошибок полей). */
    public static function error(string $code, string $message, int $status = 400, ?array $details = null): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }
        return new self($status, ['success' => false, 'error' => $error]);
    }

    /** Отдача защищённого файла. Кэшировать можно только в браузере пользователя (private). */
    public static function file(string $path, string $mime, ?string $etag = null): self
    {
        $r = new self(200, []);
        $r->filePath = $path;
        $r->headers['Content-Type'] = $mime;
        $r->headers['Cache-Control'] = 'private, max-age=3600';
        if ($etag !== null) {
            $r->headers['ETag'] = '"' . $etag . '"';
        }
        return $r;
    }

    /** Скачивание сформированного файла. Имя — латиница, чтобы корректно открываться во всех браузерах и почтовых клиентах. */
    public static function download(string $bytes, string $mime, string $filename): self
    {
        $r = new self(200, []);
        $r->rawBody = $bytes;
        $r->headers['Content-Type'] = $mime;
        $r->headers['Content-Disposition'] = 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"';
        $r->headers['Cache-Control'] = 'private, no-store';
        return $r;
    }

    public static function notModified(string $etag): self
    {
        $r = new self(304, []);
        $r->headers['ETag'] = '"' . $etag . '"';
        $r->headers['Cache-Control'] = 'private, max-age=3600';
        return $r;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('X-Content-Type-Options: nosniff');
        foreach ($this->headers as $n => $v) {
            header($n . ': ' . $v);
        }
        if ($this->filePath !== null) {
            header("Content-Security-Policy: default-src 'none'; sandbox");
            header('Content-Length: ' . (string) filesize($this->filePath));
            readfile($this->filePath);
            return;
        }
        if ($this->rawBody !== null) {
            header('Content-Length: ' . (string) strlen($this->rawBody));
            echo $this->rawBody;
            return;
        }
        if ($this->status === 304) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
