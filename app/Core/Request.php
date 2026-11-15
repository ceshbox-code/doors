<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public string $method;
    public string $path;
    public array $query;
    public array $headers;
    public array $params;
    public string $ip;
    /** Поля multipart/form-data. */
    public array $post = [];
    /** @var array<int,array{name:string,tmp_name:string,size:int,error:int}> загруженные файлы из полей photo / photos */
    public array $files = [];
    /** Тело multipart превысило post_max_size, и PHP отбросил его целиком. */
    public bool $bodyTooLarge = false;
    /** Текущий пользователь; заполняется Guard после проверки сессии. */
    public ?array $user = null;
    private ?string $rawBody;

    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
        array $params = [],
        ?string $rawBody = null,
        string $ip = '0.0.0.0'
    ) {
        $this->method  = $method;
        $this->path    = $path;
        $this->query   = $query;
        $this->headers = $headers;
        $this->params  = $params;
        $this->rawBody = $rawBody;
        $this->ip      = $ip;
    }

    public static function fromGlobals(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server])) {
                $headers[$name] = (string) $_SERVER[$server];
            }
        }
        $r = new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($uri, '/'),
            $_GET,
            $headers,
            [],
            null,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')
        );
        $r->post  = $_POST;
        $r->files = self::normalizeFiles($_FILES);
        $r->bodyTooLarge = $r->isMultipart() && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$_POST && !$_FILES;
        return $r;
    }

    /**
     * Приводит $_FILES (поля photo и photos, одиночные и массивом) к плоскому списку.
     * Пустые поля «файл не выбран» отбрасываются.
     */
    public static function normalizeFiles(array $files): array
    {
        $out = [];
        foreach (['photo', 'photos'] as $key) {
            if (!isset($files[$key]) || !is_array($files[$key])) {
                continue;
            }
            $f = $files[$key];
            if (is_array($f['tmp_name'] ?? null)) {
                foreach ($f['tmp_name'] as $i => $tmp) {
                    $out[] = [
                        'name'     => (string) ($f['name'][$i] ?? ''),
                        'tmp_name' => (string) $tmp,
                        'size'     => (int) ($f['size'][$i] ?? 0),
                        'error'    => (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    ];
                }
            } else {
                $out[] = [
                    'name'     => (string) ($f['name'] ?? ''),
                    'tmp_name' => (string) ($f['tmp_name'] ?? ''),
                    'size'     => (int) ($f['size'] ?? 0),
                    'error'    => (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE),
                ];
            }
        }
        return array_values(array_filter($out, static fn(array $u): bool => $u['error'] !== UPLOAD_ERR_NO_FILE));
    }

    public function withParams(array $params): self
    {
        $c = clone $this;
        $c->params = $params;
        return $c;
    }

    public function withUser(array $user): self
    {
        $c = clone $this;
        $c->user = $user;
        return $c;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isMultipart(): bool
    {
        return stripos((string) $this->header('Content-Type'), 'multipart/form-data') === 0;
    }

    /** Поля запроса: multipart-поля или JSON-объект. */
    public function input(): array
    {
        return $this->isMultipart() ? $this->post : $this->json();
    }

    /** Тело запроса как JSON-объект. Пустое тело — пустой массив; некорректное — ошибка 400. */
    public function json(): array
    {
        $this->rawBody ??= (string) file_get_contents('php://input');
        if (trim($this->rawBody) === '') {
            return [];
        }
        $data = json_decode($this->rawBody, true);
        if (!is_array($data)) {
            throw new ApiException('INVALID_JSON', 'Тело запроса должно быть корректным JSON-объектом', 400);
        }
        return $data;
    }
}
