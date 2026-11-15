<?php
declare(strict_types=1);

/**
 * Сквозная проверка этапов 2–7 по HTTP (внутри контейнера, на 127.0.0.1).
 *
 *   docker-compose exec -T -e SMOKE_OWNER=admin -e SMOKE_OWNER_PASSWORD='...' door-app php bin/smoke.php </dev/null
 *
 * Создаёт временных пользователей smoke_*, вид работы «SMOKE ...» и тестовые фотографии, проходит основные
 * сценарии. Данные не удаляются автоматически; удалить: SMOKE_CLEANUP=1 php bin/smoke.php
 * (трогает только пользователей smoke_*, виды работ «SMOKE ...» и связанные с ними записи и файлы).
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

const BASE = 'http://127.0.0.1/api/v1';

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "В PHP нет расширения GD.\n");
    exit(2);
}

$fails = 0;
$checks = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $fails, $checks;
    $checks++;
    if (!$ok) {
        $fails++;
    }
    echo ($ok ? '  [ ok ] ' : '  [FAIL] ') . $name . ($ok || $extra === '' ? '' : "  -> {$extra}") . "\n";
}

function code(array $j): string { return (string) ($j['error']['code'] ?? ''); }

// ------------------------------------------------------------- файлы и изображения
function photoBase(): string { return Config::get('paths.storage') . '/photos'; }

function countPhotoFiles(): int
{
    $base = photoBase();
    if (!is_dir($base)) {
        return 0;
    }
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && $f->getFilename() !== '.gitkeep') {
            $n++;
        }
    }
    return $n;
}

/** Детерминированное тестовое изображение: одинаковый seed — одинаковые байты. @return array{path:string,mime:string,name:string} */
function makeImage(int $seed, string $fmt = 'png', int $w = 320, int $h = 240): array
{
    $img = imagecreatetruecolor($w, $h);
    mt_srand($seed);
    for ($i = 0; $i < 30; $i++) {
        $c = imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
        imagefilledrectangle($img, mt_rand(0, $w - 20), mt_rand(0, $h - 20), mt_rand(20, $w), mt_rand(20, $h), $c);
    }
    $path = tempnam(sys_get_temp_dir(), 'smoke_img_');
    switch ($fmt) {
        case 'jpg':  imagejpeg($img, $path, 90); $mime = 'image/jpeg'; break;
        case 'webp': imagewebp($img, $path, 85); $mime = 'image/webp'; break;
        case 'gif':  imagegif($img, $path);      $mime = 'image/gif';  break;
        default:     imagepng($img, $path);      $mime = 'image/png';  $fmt = 'png';
    }
    imagedestroy($img);
    return ['path' => $path, 'mime' => $mime, 'name' => "shot-{$seed}.{$fmt}"];
}

function fileOf(string $content, string $mime = 'image/png', string $name = 'shot.png'): array
{
    $path = tempnam(sys_get_temp_dir(), 'smoke_f_');
    file_put_contents($path, $content);
    return ['path' => $path, 'mime' => $mime, 'name' => $name];
}

/** JPEG 400x200: верхняя половина красная, нижняя синяя, с EXIF Orientation=6 (повернуть на 90° по часовой). */
function makeExifJpeg(): array
{
    $img = imagecreatetruecolor(400, 200);
    imagefilledrectangle($img, 0, 0, 399, 99, imagecolorallocate($img, 255, 0, 0));
    imagefilledrectangle($img, 0, 100, 399, 199, imagecolorallocate($img, 0, 0, 255));
    ob_start();
    imagejpeg($img, null, 95);
    $jpg = ob_get_clean();
    $tiff = "II\x2A\x00\x08\x00\x00\x00" . "\x01\x00" . "\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00" . "\x00\x00\x00\x00";
    $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;
    return fileOf(substr($jpg, 0, 2) . $app1 . substr($jpg, 2), 'image/jpeg', 'exif.jpg');
}

// ------------------------------------------------------------- HTTP-клиент
final class Client
{
    public string $cookie = '';
    public string $csrf = '';

    /** @return array{0:int,1:array,2:array} [http, json, headers] */
    public function call(string $method, string $path, ?array $body = null, array $extra = []): array
    {
        [$code, $raw, $h] = $this->exec($method, $path, $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
            $body === null ? $extra : array_merge(['Content-Type: application/json'], $extra));
        return [$code, json_decode($raw, true) ?? [], $h];
    }

    /** multipart/form-data с файлами в поле photos[]. @return array{0:int,1:array,2:array} */
    public function multipart(string $path, array $fields, array $files, array $extra = []): array
    {
        [$code, $raw, $h] = $this->exec('POST', $path, self::multipartBody($fields, $files), $extra);
        return [$code, json_decode($raw, true) ?? [], $h];
    }

    /** Ответ без разбора JSON (выдача файлов). @return array{0:int,1:string,2:array} */
    public function raw(string $method, string $path, array $extra = []): array
    {
        return $this->exec($method, $path, null, $extra);
    }

    public static function multipartBody(array $fields, array $files): array
    {
        $post = $fields;
        foreach ($files as $i => $f) {
            $post["photos[{$i}]"] = new CURLFile($f['path'], $f['mime'], $f['name']);
        }
        return $post;
    }

    /** @param string|array|null $post @return array{0:int,1:string,2:array} */
    private function exec(string $method, string $path, $post, array $extra): array
    {
        $url = str_starts_with($path, 'http') ? $path : BASE . $path;
        $ch = curl_init($url);
        $headers = array_merge(['Accept: */*'], $extra);
        if ($this->csrf !== '') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIE         => $this->cookie,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$respHeaders) {
                if (strpos($h, ':') !== false) {
                    [$k, $v] = explode(':', $h, 2);
                    $respHeaders[strtolower(trim($k))][] = trim($v);
                }
                return strlen($h);
            },
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        foreach ($respHeaders['set-cookie'] ?? [] as $sc) {            // Secure-cookie ведём вручную
            $pair = explode(';', $sc)[0];
            if (strpos($pair, 'DOORSESS=') === 0) {
                $this->cookie = $pair;
            }
        }
        return [$code, $raw, $respHeaders];
    }

    public function login(string $user, string $pass): bool
    {
        [$c, $j] = $this->call('POST', '/auth/login', ['username' => $user, 'password' => $pass]);
        if ($c === 200 && isset($j['data']['csrf_token'])) {
            $this->csrf = $j['data']['csrf_token'];
            return true;
        }
        return false;
    }
}

/** Регистрация работы: поля + фото + Idempotency-Key. */
function reg(Client $c, array $fields, string $key, array $files): array
{
    foreach ($fields as $k => $v) {
        if (is_bool($v)) {
            $fields[$k] = $v ? 'true' : 'false';
        }
    }
    return $c->multipart('/work-records', $fields, $files, ['Idempotency-Key: ' . $key]);
}

/** Параллельные запросы от имени клиента. @return array<int,array{0:int,1:array}> */
function parallel(Client $cl, array $reqs): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($reqs as $i => [$path, $fields, $files, $key]) {
        foreach ($fields as $k => $v) {
            if (is_bool($v)) {
                $fields[$k] = $v ? 'true' : 'false';
            }
        }
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => $cl->cookie, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $cl->csrf, 'Idempotency-Key: ' . $key],
            CURLOPT_POSTFIELDS => Client::multipartBody($fields, $files),
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 1.0);
    } while ($running > 0);
    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string) curl_multi_getcontent($ch), true) ?? []];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/** Параллельные JSON-запросы. @param array<int,array{0:string,1:string,2:?array}> $reqs @return array<int,array{0:int,1:array}> */
function parallelJson(Client $cl, array $reqs): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($reqs as $i => [$method, $path, $body]) {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => $cl->cookie, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $cl->csrf],
            CURLOPT_POSTFIELDS => json_encode($body ?? new stdClass(), JSON_UNESCAPED_UNICODE),
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 1.0);
    } while ($running > 0);
    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string) curl_multi_getcontent($ch), true) ?? []];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

// ------------------------------------------------------------------ очистка
if (getenv('SMOKE_CLEANUP') === '1') {
    $pdo = Database::pdo();
    $filesToDelete = [];
    $pdo->beginTransaction();
    $ids = $pdo->query("SELECT id FROM users WHERE username LIKE 'smoke\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $recIds = $pdo->query("SELECT id FROM work_records WHERE user_id IN ($in) OR created_by IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec("DELETE FROM financial_adjustments WHERE user_id IN ($in)");
        $payIds = $pdo->query("SELECT id FROM payments WHERE user_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($payIds) {
            $pin = implode(',', array_map('intval', $payIds));
            $pdo->exec("DELETE FROM payment_allocations WHERE payment_id IN ($pin)");
            $pdo->exec("DELETE FROM payments WHERE id IN ($pin)");
        }
        $runIds = $pdo->query("SELECT id FROM payroll_runs WHERE user_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($runIds) {
            $rnin = implode(',', array_map('intval', $runIds));
            $pdo->exec("DELETE FROM payroll_run_items WHERE payroll_run_id IN ($rnin)");
            $pdo->exec("DELETE FROM payroll_runs WHERE id IN ($rnin)");
        }
        if ($recIds) {
            $rin = implode(',', array_map('intval', $recIds));
            foreach ($pdo->query("SELECT file_name, optimized_file_name FROM photos WHERE work_record_id IN ($rin)")->fetchAll() as $p) {
                $filesToDelete[] = $p['file_name'];
                if ($p['optimized_file_name']) {
                    $filesToDelete[] = $p['optimized_file_name'];
                }
            }
            $pdo->exec("DELETE FROM photos WHERE work_record_id IN ($rin)");
        }
        $pdo->exec("DELETE FROM work_records WHERE user_id IN ($in) OR created_by IN ($in)");
        $pdo->exec("DELETE FROM doors WHERE created_by IN ($in) AND id NOT IN (SELECT door_id FROM work_records WHERE door_id IS NOT NULL)");
        $pdo->exec("DELETE FROM work_rates WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM idempotency_keys WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM audit_log WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM login_attempts WHERE username LIKE 'smoke\\_%'");
    }
    $pdo->exec("DELETE FROM work_types WHERE name LIKE 'SMOKE %' AND id NOT IN (SELECT work_type_id FROM work_records) AND id NOT IN (SELECT work_type_id FROM work_rates)");
    if ($ids) {
        $pdo->exec("DELETE FROM users WHERE id IN ($in)");
    }
    $pdo->exec("UPDATE door_sequences SET last_value = COALESCE((SELECT MAX(CAST(SUBSTRING(number, 3) AS UNSIGNED)) FROM doors), 0) WHERE name = 'door'");
    $pdo->commit();
    foreach ($filesToDelete as $rel) {
        @unlink(photoBase() . '/' . $rel);
    }
    echo 'Тестовые данные smoke удалены (файлов фото: ' . count($filesToDelete) . "), счётчик дверей выровнен.\n";
    exit(0);
}

$ownerUser = getenv('SMOKE_OWNER') ?: '';
$ownerPass = getenv('SMOKE_OWNER_PASSWORD') ?: '';
if ($ownerUser === '' || $ownerPass === '') {
    fwrite(STDERR, "Задайте SMOKE_OWNER и SMOKE_OWNER_PASSWORD (см. комментарий в начале файла).\n");
    exit(2);
}

$tag = bin2hex(random_bytes(3));
$pass = fn() => 'Sm0ke-' . bin2hex(random_bytes(8));
$m1 = ['u' => 'smoke_m_' . $tag, 'p' => $pass()];
$m2 = ['u' => 'smoke_n_' . $tag, 'p' => $pass()];
$dr = ['u' => 'smoke_d_' . $tag, 'p' => $pass()];

echo "== Вход и подготовка ==\n";
$owner = new Client();
check('администратор входит', $owner->login($ownerUser, $ownerPass));
$ids = [];
foreach ([[$m1, 'master'], [$m2, 'master'], [$dr, 'director']] as [$u, $role]) {
    [$c, $j] = $owner->call('POST', '/users', ['full_name' => 'Smoke ' . $role, 'username' => $u['u'], 'password' => $u['p'], 'role' => $role]);
    check("создан {$u['u']} ({$role})", $c === 201, (string) $c);
    $ids[$u['u']] = (int) ($j['data']['id'] ?? 0);
}
$masterId = $ids[$m1['u']];
[$c, $j] = $owner->call('POST', '/work-types', ['name' => 'SMOKE Ремонт ' . $tag, 'unit' => 'шт']);
check('создан вид работы (дверь обязательна, неповторяемая)', $c === 201 && $j['data']['door_required'] === true && $j['data']['allow_multiple_per_door'] === false, (string) $c);
$typeId = (int) ($j['data']['id'] ?? 0);
[$c, $j] = $owner->call('POST', '/work-types', ['name' => 'SMOKE Ремонт ' . $tag]);
check('повторное название вида работы -> 409', $c === 409 && code($j) === 'WORK_TYPE_EXISTS');

$master = new Client();
$master2 = new Client();
$director = new Client();
check('мастера и директор входят', $master->login($m1['u'], $m1['p']) && $master2->login($m2['u'], $m2['p']) && $director->login($dr['u'], $dr['p']));

echo "== Права ==\n";
[$c] = $master->call('POST', '/users', ['full_name' => 'X', 'username' => 'smoke_x', 'password' => 'xxxxxxxxxxxx', 'role' => 'master']);
check('мастер не может создавать пользователей -> 403', $c === 403);
[$c] = $master->call('POST', '/work-types', ['name' => 'SMOKE hack']);
check('мастер не может создавать виды работ -> 403', $c === 403);
[$c] = $master->call('POST', '/rates', ['user_id' => $masterId, 'work_type_id' => $typeId, 'unit_price' => '1']);
check('мастер не может назначать расценки -> 403', $c === 403);
[$c, $j] = $master->call('GET', '/work-types');
check('мастер видит активные виды работ', $c === 200 && in_array('SMOKE Ремонт ' . $tag, array_column($j['data']['items'] ?? [], 'name'), true));
[$c] = (new Client())->call('GET', '/work-types');
check('без входа -> 401', $c === 401);
$noCsrf = new Client();
$noCsrf->cookie = $master->cookie;
$img0 = makeImage(900);
[$c, $j] = reg($noCsrf, ['work_type_id' => $typeId, 'new_door' => true], 'smoke-' . $tag . '-nocsrf', [$img0]);
check('запрос без CSRF-токена -> 403', $c === 403 && code($j) === 'CSRF_INVALID', $c . ' ' . code($j));

echo "== Фотография обязательна и проверяется по содержимому ==\n";
$files0 = countPhotoFiles();
$k0 = 'smoke-' . $tag . '-0';
[$c, $j] = $master->call('POST', '/work-records', ['work_type_id' => $typeId, 'new_door' => true], ['Idempotency-Key: ' . $k0]);
check('JSON без фото -> 422 PHOTO_REQUIRED', $c === 422 && code($j) === 'PHOTO_REQUIRED', $c . ' ' . code($j));
[$c, $j] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k0, []);
check('multipart без файла -> 422 PHOTO_REQUIRED', $c === 422 && code($j) === 'PHOTO_REQUIRED', $c . ' ' . code($j));
[$c, $j] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k0, [fileOf('просто текст', 'image/png', 'fake.png')]);
check('текст под видом PNG -> 422 PHOTO_INVALID', $c === 422 && code($j) === 'PHOTO_INVALID', $c . ' ' . code($j));
[$c, $j] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k0, [fileOf("<?php echo 'x';", 'image/jpeg', 'shell.jpg')]);
check('PHP-код под видом JPEG -> 422 PHOTO_INVALID', $c === 422 && code($j) === 'PHOTO_INVALID', $c . ' ' . code($j));
$gif = makeImage(901, 'gif');
[$c, $j] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k0, [$gif]);
check('GIF не поддерживается -> 422 PHOTO_INVALID', $c === 422 && code($j) === 'PHOTO_INVALID', $c . ' ' . code($j));
$pngBytes = file_get_contents($img0['path']);
[$c, $j] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k0, [fileOf($pngBytes . str_repeat("\0", 16000000), 'image/png', 'huge.png')]);
check('файл больше 15 МБ -> 413 PHOTO_TOO_LARGE', $c === 413 && code($j) === 'PHOTO_TOO_LARGE', $c . ' ' . code($j));
[$c, $j] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k0, [$img0, ['path' => $img0['path'], 'mime' => 'image/png', 'name' => 'copy.png']]);
check('одна и та же фотография дважды -> 422 DUPLICATE_PHOTO', $c === 422 && code($j) === 'DUPLICATE_PHOTO', $c . ' ' . code($j));
[$c, $j] = $master->call('GET', '/work-records?status=all');
check('после всех отказов у мастера нет ни одной записи', $c === 200 && ($j['data']['total'] ?? -1) === 0, json_encode($j['data']['total'] ?? null));
check('после отказов не осталось файлов на диске', countPhotoFiles() === $files0, countPhotoFiles() . ' vs ' . $files0);

echo "== Регистрация без расценки ==\n";
[$c, $j] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k0, [$img0]);
check('нет расценки -> 422 RATE_NOT_FOUND', $c === 422 && code($j) === 'RATE_NOT_FOUND', $c . ' ' . code($j));
check('после отказа по расценке файлов не осталось', countPhotoFiles() === $files0);
[$c, $j] = $master->multipart('/work-records', ['work_type_id' => $typeId, 'new_door' => 'true'], [$img0]);
check('без Idempotency-Key -> 400', $c === 400 && code($j) === 'IDEMPOTENCY_KEY_REQUIRED', (string) $c);
[$c, $j] = reg($master, ['work_type_id' => $typeId], $k0, [$img0]);
check('для вида работы нужна дверь -> 422 DOOR_REQUIRED', $c === 422 && code($j) === 'DOOR_REQUIRED', $c . ' ' . code($j));

echo "== Расценки ==\n";
[$c, $j] = $owner->call('POST', '/rates', ['user_id' => $masterId, 'work_type_id' => $typeId, 'unit_price' => '500.00']);
check('назначена расценка 500.00', $c === 201 && $j['data']['unit_price'] === '500.00', (string) $c);
$rate1 = (int) ($j['data']['id'] ?? 0);
[$c, $j] = $owner->call('POST', '/rates', ['user_id' => $masterId, 'work_type_id' => $typeId, 'unit_price' => '-5']);
check('отрицательная цена -> 422', $c === 422);
[$c, $j] = $owner->call('POST', '/rates', ['user_id' => $masterId, 'work_type_id' => $typeId, 'unit_price' => '1', 'valid_from' => '2020-01-01T00:00:00Z']);
check('расценка прошедшей датой -> 422 RATE_IN_PAST', $c === 422 && code($j) === 'RATE_IN_PAST', $c . ' ' . code($j));

echo "== Регистрация, идемпотентность, фото ==\n";
$imgA = makeImage(1);
$k1 = 'smoke-' . $tag . '-1';
$f1 = ['work_type_id' => $typeId, 'new_door' => true, 'quantity' => '1', 'comment' => 'smoke'];
[$c, $j] = reg($master, $f1, $k1, [$imgA]);
check('новая дверь + работа + фото -> 201', $c === 201, $c . ' ' . code($j));
$rec1 = (int) ($j['data']['id'] ?? 0);
$doorNo = (string) ($j['data']['door']['number'] ?? '');
$photo1 = $j['data']['photos'][0] ?? [];
check('номер двери формата D-NNNNNN', (bool) preg_match('/^D-\d{6,}$/', $doorNo), $doorNo);
check('сумма = 1 × 500.00', ($j['data']['total_amount'] ?? '') === '500.00' && ($j['data']['unit_price'] ?? '') === '500.00');
check('в ответе одна фотография, photos_count = 1, SHA-256 совпадает', ($j['data']['photos_count'] ?? 0) === 1 && count($j['data']['photos'] ?? []) === 1 && ($photo1['sha256'] ?? '') === hash_file('sha256', $imgA['path']));
check('на диске созданы оригинал и оптимизированная копия', countPhotoFiles() === $files0 + 2, (string) (countPhotoFiles() - $files0));
$filesAfter1 = countPhotoFiles();

[$c, $j2, $h] = reg($master, $f1, $k1, [$imgA]);
check('повтор с тем же ключом и тем же фото -> тот же результат', $c === 201 && ($j2['data']['id'] ?? 0) === $rec1 && isset($h['idempotent-replay']), $c . ' ' . code($j2));
check('повтор не создал новых файлов', countPhotoFiles() === $filesAfter1);
[$c, $j2] = reg($master, $f1, $k1, [makeImage(2)]);
check('тот же ключ с другим фото -> 422 IDEMPOTENCY_KEY_REUSED', $c === 422 && code($j2) === 'IDEMPOTENCY_KEY_REUSED', $c . ' ' . code($j2));
[$c, $j2] = reg($master, ['work_type_id' => $typeId, 'door_number' => $doorNo], $k1 . 'b', [makeImage(3)]);
check('повторная неповторяемая работа -> 409 DUPLICATE_WORK', $c === 409 && code($j2) === 'DUPLICATE_WORK', $c . ' ' . code($j2));
check('после 409 новых файлов нет', countPhotoFiles() === $filesAfter1);
[$c, $j2] = reg($master, ['work_type_id' => $typeId, 'door_number' => 'D-999999'], $k1 . 'c', [makeImage(4)]);
check('несуществующая дверь -> 404', $c === 404 && code($j2) === 'DOOR_NOT_FOUND');
[$c, $j2] = $owner->call('GET', '/work-records?door_number=' . $doorNo . '&status=all');
check('в БД ровно одна запись по этой двери', ($j2['data']['total'] ?? -1) === 1);

echo "== Выдача фотографий и права доступа ==\n";
$pid = (int) ($photo1['id'] ?? 0);
[$c, $body, $h] = $master->raw('GET', '/photos/' . $pid);
check('владелец получает оптимизированную копию: 200 image/jpeg', $c === 200 && str_contains($h['content-type'][0] ?? '', 'image/jpeg') && str_starts_with($body, "\xFF\xD8"), $c . ' ' . ($h['content-type'][0] ?? ''));
check('заголовки безопасности: nosniff, CSP, private-кэш', ($h['x-content-type-options'][0] ?? '') === 'nosniff' && isset($h['content-security-policy']) && str_contains($h['cache-control'][0] ?? '', 'private'));
$etag = trim($h['etag'][0] ?? '', '"');
[$c, $body2] = $master->raw('GET', '/photos/' . $pid, ['If-None-Match: "' . $etag . '"']);
check('повторный запрос с ETag -> 304 без тела', $c === 304 && $body2 === '', (string) $c);
[$c, $body, $h] = $master->raw('GET', '/photos/' . $pid . '?variant=original');
check('оригинал отдаётся без изменений (SHA-256 и тип совпадают)', $c === 200 && hash('sha256', $body) === hash_file('sha256', $imgA['path']) && str_contains($h['content-type'][0] ?? '', 'image/png'), (string) $c);
[$c] = $director->raw('GET', '/photos/' . $pid);
check('директор видит фотографии -> 200', $c === 200);
[$c] = $owner->raw('GET', '/photos/' . $pid);
check('администратор видит фотографии -> 200', $c === 200);
[$c] = $master2->raw('GET', '/photos/' . $pid);
check('чужой мастер не видит фото -> 404', $c === 404, (string) $c);
[$c] = (new Client())->raw('GET', '/photos/' . $pid);
check('без входа -> 401', $c === 401);
[$c] = $master->raw('GET', '/photos/' . $pid . '?variant=../../etc/passwd');
check('недопустимый variant -> 422', $c === 422, (string) $c);
[$c] = (new Client())->raw('GET', 'http://127.0.0.1/storage/photos/');
[$c2] = (new Client())->raw('GET', 'http://127.0.0.1/storage/');
check('каталог storage/ из интернета недоступен', in_array($c, [403, 404], true) && in_array($c2, [403, 404], true), "$c / $c2");
[$c, $j2] = $master->call('GET', '/work-records/' . $rec1 . '/photos');
check('список фото работы: владелец видит 1', $c === 200 && count($j2['data'] ?? []) === 1);
[$c] = $master2->call('GET', '/work-records/' . $rec1 . '/photos');
check('список фото чужой работы -> 404', $c === 404);
[$c, $j2] = $director->call('GET', '/work-records/' . $rec1 . '/photos');
check('директор видит список фото', $c === 200 && count($j2['data'] ?? []) === 1);

echo "== Дополнительные фотографии (POST /photos) ==\n";
$imgB = makeImage(10, 'jpg');
[$c, $j2] = $master->multipart('/photos', ['work_record_id' => (string) $rec1], [$imgB]);
check('мастер добавляет фото к своей работе -> 201, теперь 2', $c === 201 && count($j2['data'] ?? []) === 2, $c . ' ' . code($j2));
$photoB = (int) (($j2['data'] ?? [])[1]['id'] ?? 0);
[$c, $j2] = $master->multipart('/photos', ['work_record_id' => (string) $rec1], [$imgB]);
check('то же фото повторно -> 409 DUPLICATE_PHOTO', $c === 409 && code($j2) === 'DUPLICATE_PHOTO', $c . ' ' . code($j2));
[$c, $j2] = $master2->multipart('/photos', ['work_record_id' => (string) $rec1], [makeImage(11)]);
check('чужой мастер не может добавить фото -> 404', $c === 404, (string) $c);
[$c, $j2] = $master->multipart('/photos', ['work_record_id' => (string) $rec1], []);
check('без файла -> 422 PHOTO_REQUIRED', $c === 422 && code($j2) === 'PHOTO_REQUIRED');
[$c, $j2] = $owner->call('GET', '/work-records/' . $rec1);
check('карточка работы содержит photos_count = 2 и список фото', ($j2['data']['photos_count'] ?? 0) === 2 && count($j2['data']['photos'] ?? []) === 2);

echo "== Оптимизация, EXIF, форматы ==\n";
[$c, $j2] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k1 . 'big', [makeImage(20, 'jpg', 3000, 2000)]);
check('большое фото (3000x2000) принято', $c === 201, $c . ' ' . code($j2));
[$c, $body] = $master->raw('GET', '/photos/' . ($j2['data']['photos'][0]['id'] ?? 0));
$dim = @getimagesizefromstring($body) ?: [0, 0];
check('оптимизированная копия уменьшена до 1600 px по длинной стороне', $c === 200 && $dim[0] === 1600 && $dim[1] === 1067, json_encode($dim));
[$c, $body, $h] = $master->raw('GET', '/photos/' . ($j2['data']['photos'][0]['id'] ?? 0) . '?variant=original');
$dimO = @getimagesizefromstring($body) ?: [0, 0];
check('оригинал сохранён в исходном разрешении', $dimO[0] === 3000 && $dimO[1] === 2000, json_encode($dimO));

if (function_exists('exif_read_data')) {
    [$c, $j2] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k1 . 'exif', [makeExifJpeg()]);
    check('JPEG с EXIF принят', $c === 201, $c . ' ' . code($j2));
    [$c, $body] = $master->raw('GET', '/photos/' . ($j2['data']['photos'][0]['id'] ?? 0));
    $im = @imagecreatefromstring($body);
    $okRot = false;
    if ($im !== false) {
        $w = imagesx($im);
        $h2 = imagesy($im);
        $right = imagecolorsforindex($im, imagecolorat($im, $w - 8, intdiv($h2, 2)));
        $left = imagecolorsforindex($im, imagecolorat($im, 8, intdiv($h2, 2)));
        $okRot = $w === 200 && $h2 === 400 && $right['red'] > 180 && $right['blue'] < 80 && $left['blue'] > 180 && $left['red'] < 80;
    }
    check('EXIF-ориентация применена (повёрнуто на 90° по часовой, красное справа)', $okRot, $im === false ? 'не изображение' : imagesx($im) . 'x' . imagesy($im));
}
if (function_exists('imagewebp')) {
    [$c, $j2] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k1 . 'webp', [makeImage(30, 'webp')]);
    check('WebP принят', $c === 201, $c . ' ' . code($j2));
    $pw = (int) ($j2['data']['photos'][0]['id'] ?? 0);
    [$c, , $h] = $master->raw('GET', '/photos/' . $pw . '?variant=original');
    check('оригинал WebP отдаётся как image/webp', str_contains($h['content-type'][0] ?? '', 'image/webp'));
}
[$c, $j2] = reg($master, ['work_type_id' => $typeId, 'new_door' => true], $k1 . 'multi', [makeImage(40), makeImage(41, 'jpg')]);
check('две фотографии за один запрос -> photos_count = 2', $c === 201 && ($j2['data']['photos_count'] ?? 0) === 2, $c . ' ' . code($j2));

echo "== Историчность расценок ==\n";
sleep(2);
[$c, $j] = $owner->call('POST', '/rates', ['user_id' => $masterId, 'work_type_id' => $typeId, 'unit_price' => '600.00']);
check('новая расценка 600.00 закрыла прежнюю', $c === 201, $c . ' ' . code($j));
[$c, $j] = $owner->call('GET', '/rates/' . $rate1);
check('прежняя расценка получила valid_to, цена не изменилась', ($j['data']['valid_to'] ?? null) !== null && ($j['data']['unit_price'] ?? '') === '500.00');
[$c, $j] = reg($master, ['work_type_id' => $typeId, 'new_door' => true, 'quantity' => '2'], $k1 . 'd', [makeImage(50)]);
check('новая работа считается по 600.00 (2 × 600.00 = 1200.00)', $c === 201 && ($j['data']['total_amount'] ?? '') === '1200.00', $c . ' ' . ($j['data']['total_amount'] ?? code($j)));
[$c, $j] = $owner->call('GET', '/work-records/' . $rec1);
check('старое начисление осталось 500.00', ($j['data']['total_amount'] ?? '') === '500.00');

echo "== Просмотр и права на чтение ==\n";
[$c, $j] = $master->call('GET', '/doors/number/' . strtolower($doorNo));
check('поиск двери по номеру (краткая карточка для мастера)', $c === 200 && isset($j['data']['registered_work_types']) && !isset($j['data']['history']));
[$c] = $master->call('GET', '/doors');
check('мастер не видит список дверей -> 403', $c === 403);
[$c, $j] = $owner->call('GET', '/doors/number/' . $doorNo);
check('администратор видит карточку с историей', $c === 200 && count($j['data']['history'] ?? []) === 1);
[$c, $j] = $master2->call('GET', '/work-records?status=all');
check('другой мастер не видит чужих работ', $c === 200 && ($j['data']['total'] ?? -1) === 0);

echo "== Аннулирование и удаление фото ==\n";
[$c] = $master->call('POST', '/work-records/' . $rec1 . '/void', ['reason' => 'ошибка ввода']);
check('мастер не может аннулировать -> 403', $c === 403);
[$c, $j] = $owner->call('POST', '/work-records/' . $rec1 . '/void', ['reason' => 'x']);
check('причина обязательна (от 3 символов) -> 422', $c === 422);
[$c, $j] = $master->call('DELETE', '/photos/' . $photoB, ['reason' => 'удалить']);
check('мастер не может удалять фото -> 403', $c === 403);
[$c, $j] = $owner->call('DELETE', '/photos/' . $photoB, ['reason' => 'Лишний снимок']);
check('фото действительной работы удалять нельзя -> 409 PHOTO_LINKED', $c === 409 && code($j) === 'PHOTO_LINKED', $c . ' ' . code($j));
[$c, $j] = $owner->call('POST', '/work-records/' . $rec1 . '/void', ['reason' => 'Ошибочная запись (smoke)']);
check('аннулирование -> 200, статус voided', $c === 200 && ($j['data']['status'] ?? '') === 'voided', $c . ' ' . code($j));
[$c, $j] = $owner->call('POST', '/work-records/' . $rec1 . '/void', ['reason' => 'ещё раз']);
check('повторное аннулирование -> 409', $c === 409 && code($j) === 'ALREADY_VOIDED');
[$c, $j] = $owner->call('GET', '/work-records/' . $rec1 . '/photos');
check('при аннулировании фотографии сохраняются (2 шт.)', $c === 200 && count($j['data'] ?? []) === 2);
[$c, $j] = $master->multipart('/photos', ['work_record_id' => (string) $rec1], [makeImage(60)]);
check('к аннулированной работе фото добавлять нельзя -> 409 WORK_VOIDED', $c === 409 && code($j) === 'WORK_VOIDED', $c . ' ' . code($j));
$row = Database::pdo()->prepare('SELECT file_name, optimized_file_name FROM photos WHERE id = ?');
$row->execute([$photoB]);
$paths = $row->fetch();
[$c, $j] = $owner->call('DELETE', '/photos/' . $photoB, ['reason' => 'Лишний снимок']);
check('фото аннулированной работы можно удалить по праву администратора -> 200', $c === 200 && ($j['data']['deleted'] ?? false) === true, $c . ' ' . code($j));
[$c] = $owner->raw('GET', '/photos/' . $photoB);
check('удалённое фото больше не выдаётся -> 404', $c === 404);
[$c, $j] = $owner->call('GET', '/work-records/' . $rec1 . '/photos');
check('в списке осталась 1 фотография', count($j['data'] ?? []) === 1);
check('файлы удалённого фото физически сохранены на диске (мягкое удаление)', is_file(photoBase() . '/' . $paths['file_name']) && is_file(photoBase() . '/' . $paths['optimized_file_name']));
[$c, $j] = reg($master, ['work_type_id' => $typeId, 'door_number' => $doorNo], $k1 . 'e', [makeImage(61)]);
check('после аннулирования повторная регистрация разрешена', $c === 201, $c . ' ' . code($j));
[$c, $j] = $owner->call('GET', '/doors/' . ($j['data']['door']['id'] ?? 0) . '/history');
check('история двери хранит обе записи (аннулированную и новую)', $c === 200 && count($j['data'] ?? []) === 2, $c . ' ' . count($j['data'] ?? []));

echo "== Параллельные запросы ==\n";
$before = countPhotoFiles();
$reqs = [];
for ($i = 0; $i < 8; $i++) {
    $reqs[] = ['/work-records', ['work_type_id' => $typeId, 'new_door' => true], [makeImage(100 + $i)], "smoke-{$tag}-par{$i}"];
}
$res = parallel($master, $reqs);
$numbers = [];
$allOk = true;
foreach ($res as [$st, $body]) {
    $allOk = $allOk && $st === 201;
    $numbers[] = $body['data']['door']['number'] ?? '';
}
check('8 параллельных регистраций новых дверей с фото успешны', $allOk, json_encode(array_column($res, 0)));
check('номера дверей уникальны', count(array_unique($numbers)) === 8, implode(',', $numbers));
check('создано ровно 16 файлов (по 2 на фото)', countPhotoFiles() - $before === 16, (string) (countPhotoFiles() - $before));

$before = countPhotoFiles();
$sameImg = makeImage(200);
$same = [];
for ($i = 0; $i < 6; $i++) {
    $same[] = ['/work-records', ['work_type_id' => $typeId, 'new_door' => true], [$sameImg], "smoke-{$tag}-same"];
}
$res = parallel($master, $same);
$ids2 = [];
foreach ($res as [$st, $body]) {
    $ids2[] = $st . ':' . ($body['data']['id'] ?? code($body));
}
check('6 параллельных запросов с ОДНИМ ключом создали одну запись', count(array_unique(array_map(fn($x) => explode(':', $x)[1], $ids2))) === 1, implode(' ', $ids2));
check('при этом создано ровно 2 файла, а не 12', countPhotoFiles() - $before === 2, (string) (countPhotoFiles() - $before));

[$c, $j] = $owner->call('POST', '/work-types', ['name' => 'SMOKE Второй ' . $tag]);
$type2 = (int) ($j['data']['id'] ?? 0);
$owner->call('POST', '/rates', ['user_id' => $masterId, 'work_type_id' => $type2, 'unit_price' => '100']);
[$c, $j] = reg($master, ['work_type_id' => $type2, 'new_door' => true], "smoke-{$tag}-target", [makeImage(300)]);
$target = (string) ($j['data']['door']['number'] ?? '');
check('подготовлена дверь для теста гонки', $c === 201 && $target !== '', $c . ' ' . code($j));
$before = countPhotoFiles();
$dup = [];
for ($i = 0; $i < 6; $i++) {
    $dup[] = ['/work-records', ['work_type_id' => $typeId, 'door_number' => $target], [makeImage(310 + $i)], "smoke-{$tag}-dup{$i}"];
}
$codes = array_column(parallel($master, $dup), 0);
sort($codes);
check('6 одновременных регистраций одной работы на одну дверь: 1 успех и 5 отказов 409', $codes === [201, 409, 409, 409, 409, 409], json_encode($codes));
check('отклонённые запросы не оставили файлов (только 2 файла победителя)', countPhotoFiles() - $before === 2, (string) (countPhotoFiles() - $before));
[$c, $j] = $owner->call('GET', '/work-records?door_number=' . $target . '&work_type_id=' . $typeId);
check('в БД по этой двери и работе ровно одна запись', ($j['data']['total'] ?? -1) === 1, json_encode($j['data']['total'] ?? null));


echo "== Финансы: начисления, выплаты, распределение ==\n";
$tzName = (string) Config::get('app.timezone', 'UTC');
$today = (new DateTimeImmutable('now', new DateTimeZone($tzName)))->format('Y-m-d');
$yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone($tzName)))->format('Y-m-d');
$m3 = ['u' => 'smoke_f_' . $tag, 'p' => $pass()];
[$c, $j] = $owner->call('POST', '/users', ['full_name' => 'Smoke finance', 'username' => $m3['u'], 'password' => $m3['p'], 'role' => 'master']);
$m3id = (int) ($j['data']['id'] ?? 0);
$master3 = new Client();
check('создан и вошёл исполнитель для финансов', $c === 201 && $master3->login($m3['u'], $m3['p']), (string) $c);
$owner->call('POST', '/rates', ['user_id' => $m3id, 'work_type_id' => $typeId, 'unit_price' => '100.00']);
$w = [];
foreach (['1', '2.5', '4'] as $i => $qty) {
    [$c, $j] = reg($master3, ['work_type_id' => $typeId, 'new_door' => true, 'quantity' => $qty], "smoke-{$tag}-f{$i}", [makeImage(700 + $i)]);
    $w[$i] = (int) ($j['data']['id'] ?? 0);
}
check('зарегистрированы 3 работы: 100.00 + 250.00 + 400.00', count(array_filter($w)) === 3);
$totals = fn() => $owner->call('GET', '/finance/users/' . $m3id)[1]['data']['totals'] ?? [];
$t = $totals();
check('начислено 750.00, выплачено 0.00, остаток 750.00', ($t['accrued'] ?? '') === '750.00' && ($t['paid'] ?? '') === '0.00' && ($t['balance'] ?? '') === '750.00' && ($t['debt'] ?? '') === '750.00' && ($t['overpayment'] ?? '') === '0.00', json_encode($t));

echo "-- права --\n";
[$c] = $master3->call('GET', '/finance/users/' . $m3id);
check('исполнитель видит свои финансы -> 200', $c === 200);
[$c] = $master->call('GET', '/finance/users/' . $m3id);
check('чужой исполнитель не видит финансы -> 404', $c === 404, (string) $c);
[$c] = $director->call('GET', '/finance/users/' . $m3id);
check('директор видит финансы любого исполнителя -> 200', $c === 200);
[$c] = $master3->call('GET', '/finance/balances');
check('исполнителю остатки всех недоступны -> 403', $c === 403);
[$c, $j] = $director->call('GET', '/finance/balances');
$row3 = array_values(array_filter($j['data']['items'] ?? [], fn($r) => ($r['user']['id'] ?? 0) === $m3id))[0] ?? [];
check('директор видит остатки всех исполнителей', $c === 200 && ($row3['balance'] ?? '') === '750.00', $c . ' ' . json_encode($row3));
[$c, $j] = $master3->call('GET', '/finance/accruals');
check('исполнитель видит только свои начисления (3, все неоплачены)', $c === 200 && ($j['data']['total'] ?? 0) === 3 && count(array_filter($j['data']['items'], fn($x) => $x['payment_state'] === 'unpaid')) === 3);
[$c] = $master3->call('POST', '/payments', ['user_id' => $m3id, 'amount' => '10', 'payment_method' => 'cash'], ['Idempotency-Key: ' . "smoke-{$tag}-denied"]);
check('исполнитель не может создавать выплаты -> 403', $c === 403);
[$c] = $director->call('POST', '/payments', ['user_id' => $m3id, 'amount' => '10', 'payment_method' => 'cash'], ['Idempotency-Key: ' . "smoke-{$tag}-denied2"]);
check('директор не может создавать выплаты -> 403', $c === 403);
[$c] = $master3->call('POST', '/payroll-runs', ['user_id' => $m3id, 'period_from' => $today, 'period_to' => $today]);
check('исполнитель не может формировать расчёты -> 403', $c === 403);

echo "-- выплата: создание и распределение --\n";
$pay = fn(Client $cl, array $body, string $k) => $cl->call('POST', '/payments', $body, ['Idempotency-Key: ' . $k]);
[$c, $j] = $pay($owner, ['user_id' => $m3id, 'amount' => '10000', 'payment_method' => 'cash'], "smoke-{$tag}-big");
check('сумма больше неоплаченных начислений -> 422 AMOUNT_EXCEEDS_OUTSTANDING', $c === 422 && code($j) === 'AMOUNT_EXCEEDS_OUTSTANDING', $c . ' ' . code($j));
[$c, $j] = $pay($owner, ['user_id' => $m3id, 'amount' => '1000', 'payment_method' => 'cash', 'allocations' => [['work_record_id' => $w[0], 'amount' => '1000']]], "smoke-{$tag}-over");
check('распределение сверх остатка начисления -> 409 ALLOCATION_EXCEEDS_OUTSTANDING', $c === 409 && code($j) === 'ALLOCATION_EXCEEDS_OUTSTANDING', $c . ' ' . code($j));
[$c, $j] = $pay($owner, ['user_id' => $m3id, 'amount' => '100', 'payment_method' => 'cash', 'allocations' => [['work_record_id' => $w[0], 'amount' => '50']]], "smoke-{$tag}-sum");
check('сумма распределения не равна сумме выплаты -> 422 ALLOCATION_SUM_MISMATCH', $c === 422 && code($j) === 'ALLOCATION_SUM_MISMATCH', $c . ' ' . code($j));
[$c, $j] = $pay($owner, ['user_id' => $m3id, 'amount' => '10', 'payment_method' => 'cash', 'allocations' => [['work_record_id' => $rec1, 'amount' => '10']]], "smoke-{$tag}-alien");
check('распределение на работу другого исполнителя -> 422 ALLOCATION_INVALID', $c === 422 && code($j) === 'ALLOCATION_INVALID', $c . ' ' . code($j));
[$c, $j] = $pay($owner, ['user_id' => $m3id, 'amount' => '10', 'payment_method' => ''], "smoke-{$tag}-nm");
check('способ выплаты обязателен -> 422', $c === 422);
[$c, $j] = $owner->call('POST', '/payments', ['user_id' => $m3id, 'amount' => '10', 'payment_method' => 'cash']);
check('без Idempotency-Key -> 400', $c === 400 && code($j) === 'IDEMPOTENCY_KEY_REQUIRED');

$kp1 = "smoke-{$tag}-p1";
$p1body = ['user_id' => $m3id, 'amount' => '300', 'payment_method' => 'наличные', 'reference' => 'RKO-1', 'comment' => 'аванс'];
[$c, $j, $h] = $pay($owner, $p1body, $kp1);
$p1 = (int) ($j['data']['id'] ?? 0);
$al = $j['data']['allocations'] ?? [];
check('черновик выплаты 300.00: автораспределение на самые ранние начисления (100.00 + 200.00)', $c === 201 && ($j['data']['status'] ?? '') === 'draft' && count($al) === 2
    && $al[0]['work_record_id'] === $w[0] && $al[0]['amount'] === '100.00' && $al[1]['work_record_id'] === $w[1] && $al[1]['amount'] === '200.00' && ($j['data']['allocated_total'] ?? '') === '300.00', $c . ' ' . json_encode($al));
[$c, $j2, $h] = $pay($owner, $p1body, $kp1);
check('повтор создания выплаты с тем же ключом не создаёт вторую', $c === 201 && ($j2['data']['id'] ?? 0) === $p1 && isset($h['idempotent-replay']), $c . ' ' . code($j2));
[$c, $j2] = $pay($owner, ['amount' => '301'] + $p1body, $kp1);
check('тот же ключ с другой суммой -> 422 IDEMPOTENCY_KEY_REUSED', $c === 422 && code($j2) === 'IDEMPOTENCY_KEY_REUSED', $c . ' ' . code($j2));
[$c, $j2] = $owner->call('GET', '/payments?user_id=' . $m3id);
check('в системе ровно одна выплата получателя', ($j2['data']['total'] ?? -1) === 1);
$t = $totals();
check('черновик выплаты не уменьшает остаток (750.00)', ($t['paid'] ?? '') === '0.00' && ($t['balance'] ?? '') === '750.00');
[$c, $j2] = $master3->call('GET', '/payments');
check('исполнитель не видит черновики выплат', $c === 200 && ($j2['data']['total'] ?? -1) === 0);

[$c, $j] = $owner->call('PUT', '/payments/' . $p1 . '/allocations', ['allocations' => [['work_record_id' => $w[0], 'amount' => '100'], ['work_record_id' => $w[2], 'amount' => '200']]]);
$al = $j['data']['allocations'] ?? [];
check('администратор меняет распределение черновика (теперь на 1-ю и 3-ю работы)', $c === 200 && count($al) === 2 && $al[1]['work_record_id'] === $w[2] && ($j['data']['allocated_total'] ?? '') === '300.00', $c . ' ' . code($j));
[$c, $j] = $owner->call('PUT', '/payments/' . $p1 . '/allocations', ['allocations' => [['work_record_id' => $w[0], 'amount' => '300']]]);
check('некорректное распределение отклоняется', $c === 409 && code($j) === 'ALLOCATION_EXCEEDS_OUTSTANDING', $c . ' ' . code($j));

[$c, $j] = $owner->call('POST', '/payments/' . $p1 . '/post');
check('подтверждение выплаты -> posted', $c === 200 && ($j['data']['status'] ?? '') === 'posted' && ($j['data']['posted_at'] ?? null) !== null, $c . ' ' . code($j));
[$c, $j] = $owner->call('POST', '/payments/' . $p1 . '/post');
check('повторное подтверждение -> 409 PAYMENT_NOT_DRAFT (нельзя зачесть дважды)', $c === 409 && code($j) === 'PAYMENT_NOT_DRAFT', $c . ' ' . code($j));
[$c, $j] = $owner->call('PUT', '/payments/' . $p1 . '/allocations', ['allocations' => [['work_record_id' => $w[1], 'amount' => '300']]]);
check('подтверждённую выплату распределением не править -> 409', $c === 409 && code($j) === 'PAYMENT_NOT_DRAFT');
$t = $totals();
check('после подтверждения: выплачено 300.00, остаток 450.00', ($t['paid'] ?? '') === '300.00' && ($t['balance'] ?? '') === '450.00', json_encode($t));
[$c, $j] = $master3->call('GET', '/finance/accruals?status=active');
$st = array_column($j['data']['items'] ?? [], 'payment_state', 'work_record_id');
$out = array_column($j['data']['items'] ?? [], 'outstanding', 'work_record_id');
check('статусы начислений: 1-е оплачено, 2-е не оплачено, 3-е частично (остаток 200.00)', ($st[$w[0]] ?? '') === 'paid' && ($st[$w[1]] ?? '') === 'unpaid' && ($st[$w[2]] ?? '') === 'partial' && ($out[$w[2]] ?? '') === '200.00', json_encode($st));
[$c, $j] = $master3->call('GET', '/payments');
check('исполнитель видит свою подтверждённую выплату', $c === 200 && ($j['data']['total'] ?? 0) === 1);
[$c, $j] = $master3->call('GET', '/payments/' . $p1 . '/allocations');
check('исполнитель видит распределение своей выплаты', $c === 200 && count($j['data'] ?? []) === 2);
[$c] = $master->call('GET', '/payments/' . $p1);
check('чужой исполнитель не видит выплату -> 404', $c === 404);

echo "-- гонка: два черновика на один и тот же остаток --\n";
$mk = function (string $k) use ($owner, $m3id, $w, $pay) {
    [$c, $j] = $pay($owner, ['user_id' => $m3id, 'amount' => '200', 'payment_method' => 'cash', 'allocations' => [['work_record_id' => $w[2], 'amount' => '200']]], $k);
    return (int) ($j['data']['id'] ?? 0);
};
$pa = $mk("smoke-{$tag}-ra");
$pb = $mk("smoke-{$tag}-rb");
check('созданы два черновика по 200.00 на остаток 3-й работы', $pa > 0 && $pb > 0 && $pa !== $pb);
$res = parallelJson($owner, [['POST', "/payments/{$pa}/post", null], ['POST', "/payments/{$pb}/post", null]]);
$codes = array_column($res, 0);
sort($codes);
check('одновременное подтверждение: один успех (200) и один отказ (409)', $codes === [200, 409], json_encode($codes));
$loserCode = '';
foreach ($res as [$st2, $body2]) {
    if ($st2 === 409) {
        $loserCode = code($body2);
    }
}
check('отказ по причине превышения остатка начисления', $loserCode === 'ALLOCATION_EXCEEDS_OUTSTANDING', $loserCode);
$t = $totals();
check('остаток засчитан один раз: выплачено 500.00, остаток 250.00', ($t['paid'] ?? '') === '500.00' && ($t['balance'] ?? '') === '250.00', json_encode($t));
$loser = ($res[0][0] === 409) ? $pa : $pb;
$winner = $loser === $pa ? $pb : $pa;
[$c, $j] = $owner->call('POST', "/payments/{$loser}/cancel", ['reason' => 'дубль выплаты']);
check('отмена черновика-неудачника без корректировки', $c === 200 && ($j['data']['status'] ?? '') === 'cancelled');

echo "-- расчёт за период --\n";
[$c, $j] = $owner->call('POST', '/payroll-runs', ['user_id' => $m3id, 'period_from' => $today, 'period_to' => $today]);
$run1 = (int) ($j['data']['id'] ?? 0);
check('черновик расчёта за сегодня: 3 позиции на 750.00', $c === 201 && ($j['data']['status'] ?? '') === 'draft' && count($j['data']['items'] ?? []) === 3 && ($j['data']['total_amount'] ?? '') === '750.00', $c . ' ' . code($j));
check('в расчёте видны выплаты и остаток (оплачено 500.00, остаток 250.00)', ($j['data']['totals']['paid'] ?? '') === '500.00' && ($j['data']['totals']['remaining'] ?? '') === '250.00', json_encode($j['data']['totals'] ?? null));
[$c, $j] = $owner->call('POST', '/payroll-runs', ['user_id' => $m3id, 'period_from' => $today, 'period_to' => $today]);
check('второй расчёт на те же работы -> 422 NO_WORKS_IN_PERIOD', $c === 422 && code($j) === 'NO_WORKS_IN_PERIOD' && ($j['error']['details']['already_in_other_runs'] ?? 0) === 3, $c . ' ' . code($j));
[$c, $j] = $owner->call('POST', '/work-records/' . $w[1] . '/void', ['reason' => 'проверка черновика расчёта']);
check('работу из черновика расчёта аннулировать нельзя -> 409', $c === 409 && code($j) === 'WORK_IN_DRAFT_PAYROLL', $c . ' ' . code($j));
[$c, $j] = $pay($owner, ['user_id' => $m3id, 'amount' => '10', 'payment_method' => 'cash', 'payroll_run_id' => $run1], "smoke-{$tag}-draftrun");
check('выплата по неподтверждённому расчёту -> 409 RUN_NOT_CONFIRMED', $c === 409 && code($j) === 'RUN_NOT_CONFIRMED', $c . ' ' . code($j));
[$c, $j] = $owner->call('POST', "/payroll-runs/{$run1}/cancel", ['reason' => 'пересоздаём']);
check('отмена черновика расчёта освобождает работы', $c === 200 && ($j['data']['status'] ?? '') === 'cancelled');
[$c, $j] = $master3->call('GET', "/payroll-runs/{$run1}");
check('исполнитель не видит отменённый расчёт -> 404', $c === 404);
[$c, $j] = $owner->call('POST', '/payroll-runs', ['user_id' => $m3id, 'period_from' => $today, 'period_to' => $today]);
$run2 = (int) ($j['data']['id'] ?? 0);
check('расчёт сформирован заново (работы освобождены)', $c === 201 && ($j['data']['total_amount'] ?? '') === '750.00', $c . ' ' . code($j));
[$c, $j] = $master3->call('GET', "/payroll-runs/{$run2}");
check('исполнитель не видит черновик расчёта -> 404', $c === 404);
[$c, $j] = $owner->call('POST', "/payroll-runs/{$run2}/confirm");
check('подтверждение расчёта', $c === 200 && ($j['data']['status'] ?? '') === 'confirmed' && ($j['data']['confirmed_at'] ?? null) !== null, $c . ' ' . code($j));
[$c, $j] = $owner->call('POST', "/payroll-runs/{$run2}/confirm");
check('повторное подтверждение -> 409 RUN_NOT_DRAFT', $c === 409 && code($j) === 'RUN_NOT_DRAFT');
[$c, $j] = $master3->call('GET', "/payroll-runs/{$run2}");
check('исполнитель видит свой подтверждённый расчёт', $c === 200 && count($j['data']['items'] ?? []) === 3);

echo "-- расчёт неизменяем --\n";
sleep(2);
$owner->call('POST', '/rates', ['user_id' => $m3id, 'work_type_id' => $typeId, 'unit_price' => '120.00']);
[$c, $j] = reg($master3, ['work_type_id' => $typeId, 'new_door' => true, 'quantity' => '1'], "smoke-{$tag}-f3", [makeImage(710)]);
$w[3] = (int) ($j['data']['id'] ?? 0);
check('новая работа после смены расценки: 120.00', $c === 201 && ($j['data']['total_amount'] ?? '') === '120.00', $c . ' ' . code($j));
[$c, $j] = $owner->call('GET', "/payroll-runs/{$run2}");
check('подтверждённый расчёт не изменился (750.00, 3 позиции, цены прежние)', ($j['data']['total_amount'] ?? '') === '750.00' && count($j['data']['items'] ?? []) === 3 && ($j['data']['items'][2]['unit_price'] ?? '') === '100.00', json_encode($j['data']['totals'] ?? null));

echo "-- выплата по расчёту --\n";
[$c, $j] = $pay($owner, ['user_id' => $m3id, 'amount' => '250', 'payment_method' => 'перевод', 'reference' => 'PP-77', 'payroll_run_id' => $run2, 'post' => true], "smoke-{$tag}-byrun");
$p3 = (int) ($j['data']['id'] ?? 0);
check('выплата остатка расчёта 250.00 создана и сразу подтверждена', $c === 201 && ($j['data']['status'] ?? '') === 'posted' && ($j['data']['allocations'][0]['work_record_id'] ?? 0) === $w[1], $c . ' ' . code($j));
[$c, $j] = $pay($owner, ['user_id' => $m3id, 'amount' => '1', 'payment_method' => 'cash', 'payroll_run_id' => $run2], "smoke-{$tag}-runfull");
check('расчёт выплачен полностью: дальнейшая выплата по нему -> 422', $c === 422 && code($j) === 'AMOUNT_EXCEEDS_OUTSTANDING', $c . ' ' . code($j));
[$c, $j] = $owner->call('GET', "/payroll-runs/{$run2}");
check('в расчёте остаток 0.00, оплачено 750.00', ($j['data']['totals']['remaining'] ?? '') === '0.00' && ($j['data']['totals']['paid'] ?? '') === '750.00', json_encode($j['data']['totals'] ?? null));
$t = $totals();
check('остаток исполнителя = 120.00 (только новая работа вне расчёта)', ($t['accrued'] ?? '') === '870.00' && ($t['paid'] ?? '') === '750.00' && ($t['balance'] ?? '') === '120.00', json_encode($t));
[$c, $j] = $owner->call('POST', "/payroll-runs/{$run2}/cancel", ['reason' => 'проверка запрета']);
check('расчёт с выплатами отменить нельзя -> 409 RUN_HAS_PAYMENTS', $c === 409 && code($j) === 'RUN_HAS_PAYMENTS', $c . ' ' . code($j));

echo "-- аннулирование оплаченной работы --\n";
[$c, $j] = $owner->call('POST', '/work-records/' . $w[0] . '/void', ['reason' => 'ошибочная запись (smoke, оплачена)']);
check('оплаченную работу из подтверждённого расчёта аннулировать можно -> 200', $c === 200 && ($j['data']['status'] ?? '') === 'voided', $c . ' ' . code($j));
[$c, $j] = $owner->call('GET', '/finance/adjustments?user_id=' . $m3id);
$adj = $j['data']['items'] ?? [];
$byType = [];
foreach ($adj as $a) {
    $byType[$a['type']] = $a;
}
check('создана корректировка void_paid_work -100.00 (история выплаты не удалена)', isset($byType['void_paid_work']) && $byType['void_paid_work']['amount'] === '-100.00' && $byType['void_paid_work']['affects_balance'] === false && $byType['void_paid_work']['work_record_id'] === $w[0], json_encode($adj));
check('создана корректировка payroll_correction -100.00 по расчёту', isset($byType['payroll_correction']) && $byType['payroll_correction']['amount'] === '-100.00' && $byType['payroll_correction']['payroll_run_id'] === $run2);
$t = $totals();
check('остаток: начислено 770.00, выплачено 750.00 (оплата аннулированной работы сохранена), остаток 20.00', ($t['accrued'] ?? '') === '770.00' && ($t['paid'] ?? '') === '750.00' && ($t['balance'] ?? '') === '20.00', json_encode($t));
[$c, $j] = $owner->call('GET', "/payroll-runs/{$run2}");
check('расчёт хранит снимок 750.00; аннулированная позиция помечена, оплата по ней видна отдельно', ($j['data']['total_amount'] ?? '') === '750.00' && ($j['data']['items'][0]['voided'] ?? false) === true
    && ($j['data']['totals']['voided_total'] ?? '') === '100.00' && ($j['data']['totals']['paid_on_voided_work'] ?? '') === '100.00' && ($j['data']['totals']['remaining'] ?? '') === '0.00', json_encode($j['data']['totals'] ?? null));

echo "-- отмена выплаты и корректировки --\n";
[$c, $j] = $master3->call('POST', "/payments/{$p1}/cancel", ['reason' => 'не моё']);
check('исполнитель не может отменять выплаты -> 403', $c === 403);
[$c, $j] = $owner->call('POST', "/payments/{$p1}/cancel", ['reason' => 'x']);
check('причина отмены обязательна -> 422', $c === 422);
[$c, $j] = $owner->call('POST', "/payments/{$p1}/cancel", ['reason' => 'Ошибочная выплата (smoke)']);
check('отмена подтверждённой выплаты -> cancelled', $c === 200 && ($j['data']['status'] ?? '') === 'cancelled', $c . ' ' . code($j));
[$c, $j] = $owner->call('POST', "/payments/{$p1}/cancel", ['reason' => 'ещё раз']);
check('повторная отмена -> 409', $c === 409 && code($j) === 'PAYMENT_ALREADY_CANCELLED');
[$c, $j] = $owner->call('GET', '/finance/adjustments?user_id=' . $m3id . '&type=cancel_payment');
check('создана корректировка cancel_payment +300.00', ($j['data']['items'][0]['amount'] ?? '') === '300.00' && ($j['data']['items'][0]['payment_id'] ?? 0) === $p1 && ($j['data']['items'][0]['affects_balance'] ?? true) === false, json_encode($j['data']['items'][0] ?? null));
$t = $totals();
check('отменённая выплата не учитывается: выплачено 450.00, остаток 320.00', ($t['paid'] ?? '') === '450.00' && ($t['balance'] ?? '') === '320.00', json_encode($t));
[$c, $j] = $owner->call('GET', '/payments?user_id=' . $m3id);
check('история сохранена: все 4 выплаты в списке (2 подтверждённые, 2 отменённые)', ($j['data']['total'] ?? 0) === 4, json_encode($j['data']['total'] ?? null));
[$c, $j] = $owner->call('GET', "/payments/{$p1}/allocations");
check('распределение отменённой выплаты сохранено для истории', $c === 200 && count($j['data'] ?? []) === 2);

echo "-- ручные корректировки и переплата --\n";
[$c] = $master3->call('POST', '/finance/adjustments', ['user_id' => $m3id, 'type' => 'manual', 'amount' => '-1', 'reason' => 'тест']);
check('исполнитель не может вводить корректировки -> 403', $c === 403);
[$c, $j] = $owner->call('POST', '/finance/adjustments', ['user_id' => $m3id, 'type' => 'manual', 'amount' => '0', 'reason' => 'ноль']);
check('нулевая корректировка -> 422', $c === 422);
[$c, $j] = $owner->call('POST', '/finance/adjustments', ['user_id' => $m3id, 'type' => 'cancel_payment', 'amount' => '5', 'reason' => 'нельзя вручную']);
check('системный тип корректировки вручную -> 422', $c === 422);
[$c, $j] = $owner->call('POST', '/finance/adjustments', ['user_id' => $m3id, 'type' => 'overpayment', 'amount' => '-350', 'reason' => 'Ранее выданный аванс (smoke)']);
check('ручная корректировка -350.00 введена, учитывается в остатке', $c === 201 && ($j['data']['affects_balance'] ?? false) === true, $c . ' ' . code($j));
$t = $totals();
check('отрицательный остаток показан отдельно как переплата: остаток -30.00, долг 0.00, переплата 30.00', ($t['balance'] ?? '') === '-30.00' && ($t['debt'] ?? '') === '0.00' && ($t['overpayment'] ?? '') === '30.00', json_encode($t));

echo "-- показатели за период и сводка --\n";
[$c, $j] = $owner->call('GET', '/finance/users/' . $m3id . '?date_from=' . $today . '&date_to=' . $today);
$pr = $j['data']['period'] ?? [];
check('за сегодня: начислено 770.00, выплачено 450.00, корректировки -350.00, остаток на конец -30.00', ($pr['accrued'] ?? '') === '770.00' && ($pr['paid'] ?? '') === '450.00' && ($pr['adjustments'] ?? '') === '-350.00' && ($pr['balance_at_end'] ?? '') === '-30.00', json_encode($pr));
[$c, $j] = $owner->call('GET', '/finance/users/' . $m3id . '?date_from=' . $yesterday . '&date_to=' . $yesterday);
$pr = $j['data']['period'] ?? [];
check('за вчера: ничего не начислено, задолженность на конец периода 0.00', ($pr['accrued'] ?? '') === '0.00' && ($pr['paid'] ?? '') === '0.00' && ($pr['debt_at_end'] ?? '') === '0.00', json_encode($pr));
[$c] = $owner->call('GET', '/finance/users/' . $m3id . '?date_from=2026-13-01');
check('некорректная дата периода -> 422', $c === 422);
[$c, $j] = $director->call('GET', '/finance/summary?date_from=' . $today . '&date_to=' . $today);
check('сводка для директора: общие суммы и переплаты отдельно', $c === 200 && isset($j['data']['totals']['accrued']) && bccomp($j['data']['totals']['overpayment_total'] ?? '0', '30.00', 2) >= 0 && isset($j['data']['period']), $c . ' ' . json_encode($j['data']['totals'] ?? null));
[$c] = $master3->call('GET', '/finance/summary');
check('сводка недоступна исполнителю -> 403', $c === 403);
$audit = Database::pdo()->prepare("SELECT COUNT(*) FROM audit_log WHERE action IN ('payment.create','payment.post','payment.cancel','payroll_run.create','payroll_run.confirm','payroll_run.cancel','adjustment.create') AND ip_address IS NOT NULL");
$audit->execute();
check('все финансовые действия записаны в журнал аудита', (int) $audit->fetchColumn() >= 12);


// ============================================================================ статистика и экспорт
echo "\n== Статистика ==\n";
$qs = "user_id={$m3id}&date_from={$today}&date_to={$today}";
[$c, $j] = $director->call('GET', '/stats/summary?' . $qs);
$cn = $j['data']['counts'] ?? [];
$mo = $j['data']['money'] ?? [];
check('сводка за сегодня по исполнителю: 3 операции, 3 двери (аннулированная не считается)', $c === 200 && ($cn['operations'] ?? 0) === 3 && ($cn['doors'] ?? 0) === 3, $c . ' ' . json_encode($cn));
check('начислено 770.00, выплачено 450.00, задолженность 0.00, переплата 30.00 (как в финансах)', ($mo['accrued'] ?? '') === '770.00' && ($mo['paid'] ?? '') === '450.00' && ($mo['debt'] ?? '') === '0.00' && ($mo['overpayment'] ?? '') === '30.00', json_encode($mo));
[$c, $j2] = $director->call('GET', "/stats/summary?user_id={$m3id}&period=today");
check('готовый период «today» даёт те же данные и подпись «Сегодня»', $c === 200 && ($j2['data']['counts']['operations'] ?? 0) === 3 && str_starts_with($j2['data']['filters']['period']['label'] ?? '', 'Сегодня'), json_encode($j2['data']['filters']['period'] ?? null));
[$c, $j2] = $director->call('GET', "/stats/summary?user_id={$m3id}&period=bogus");
check('неизвестный готовый период -> 422', $c === 422);
[$c] = $director->call('GET', '/stats/summary?user_id=abc');
check('некорректный user_id -> 422', $c === 422);
[$c] = $master->call('GET', '/stats/summary');
check('исполнитель не видит статистику -> 403', $c === 403);
[$c] = (new Client())->call('GET', '/stats/summary');
check('без входа -> 401', $c === 401);
[$c, $j2] = $owner->call('GET', "/stats/summary?user_id={$m3id}&work_type_id={$typeId}&date_from={$today}&date_to={$today}");
check('при фильтре по виду работ выплаты и остаток не выводятся (null), начислено считается', $c === 200 && array_key_exists('paid', $j2['data']['money'] ?? []) && $j2['data']['money']['paid'] === null && ($j2['data']['money']['accrued'] ?? '') === '770.00', json_encode($j2['data']['money'] ?? null));

[$c, $j2] = $owner->call('PATCH', '/work-types/' . $typeId, ['category' => 'xyz']);
check('неизвестная категория вида работ -> 422', $c === 422);
[$c, $j2] = $owner->call('PATCH', '/work-types/' . $typeId, ['category' => 'repair']);
check('категория вида работ = repair', $c === 200 && ($j2['data']['category'] ?? '') === 'repair', $c . ' ' . code($j2));
[$c, $j2] = $director->call('GET', '/stats/summary?' . $qs);
check('показатели по категориям: 3 ремонта, 0 упаковок, 0 доп. работ', ($j2['data']['counts']['repairs'] ?? -1) === 3 && ($j2['data']['counts']['packings'] ?? -1) === 0 && ($j2['data']['counts']['other_works'] ?? -1) === 0, json_encode($j2['data']['counts'] ?? null));

[$c, $j2] = $director->call('GET', '/stats/daily?' . $qs);
$day = array_values(array_filter($j2['data']['items'] ?? [], fn($i) => $i['date'] === $today))[0] ?? [];
check('по дням: за сегодня 3 работы, 3 двери, 770.00', $c === 200 && ($day['works_count'] ?? 0) === 3 && ($day['doors_count'] ?? 0) === 3 && ($day['amount'] ?? '') === '770.00', json_encode($day));
[$c, $j2] = $director->call('GET', "/stats/daily?user_id={$m3id}&period=yesterday");
check('по дням: период без работ даёт непрерывный ряд с нулями', $c === 200 && count($j2['data']['items'] ?? []) === 1 && ($j2['data']['items'][0]['works_count'] ?? -1) === 0, json_encode($j2['data']['items'] ?? null));
[$c, $j2] = $director->call('GET', '/stats/work-types?' . $qs);
check('по видам работ: одна строка, 3 работы, 770.00', $c === 200 && count($j2['data']['items'] ?? []) === 1 && ($j2['data']['items'][0]['works_count'] ?? 0) === 3 && ($j2['data']['totals']['amount'] ?? '') === '770.00', json_encode($j2['data']['totals'] ?? null));
[$c, $j2] = $director->call('GET', '/stats/users?' . $qs);
$u0 = $j2['data']['items'][0] ?? [];
check('по исполнителю: 3 двери, начислено 770.00, выплачено 450.00, остаток на конец -30.00', ($u0['user']['id'] ?? 0) === $m3id && ($u0['doors_count'] ?? 0) === 3 && ($u0['accrued'] ?? '') === '770.00' && ($u0['paid'] ?? '') === '450.00' && ($u0['balance_at_end'] ?? '') === '-30.00', json_encode($u0));
check('по исполнителю: разбивка по видам работ (3 работы)', count($u0['by_work_type'] ?? []) === 1 && ($u0['by_work_type'][0]['works_count'] ?? 0) === 3);
[$c, $j2] = $director->call('GET', '/stats/doors?' . $qs);
check('по дверям: 3 двери', $c === 200 && ($j2['data']['total'] ?? 0) === 3 && count($j2['data']['items'] ?? []) === 3, json_encode($j2['data']['total'] ?? null));
[$c, $jw] = $owner->call('GET', '/work-records/' . $w[1]);
$doorOfW1 = (string) ($jw['data']['door']['number'] ?? '');
[$c, $j2] = $director->call('GET', '/stats/doors?door_number=' . $doorOfW1);
check('по двери: одна дверь, 1 работа, 250.00, 1 фото', ($j2['data']['total'] ?? 0) === 1 && ($j2['data']['items'][0]['works_count'] ?? 0) === 1 && ($j2['data']['items'][0]['amount'] ?? '') === '250.00' && ($j2['data']['items'][0]['photos_count'] ?? 0) === 1, json_encode($j2['data']['items'][0] ?? null));
[$c, $j2] = $director->call('GET', '/stats/doors?door_number=D-999999');
check('несуществующая дверь в фильтре -> пустой результат, а не ошибка', $c === 200 && ($j2['data']['total'] ?? -1) === 0);
[$c, $j2] = $director->call('GET', '/stats/summary?door_number=D-999999');
check('сводка по несуществующей двери: нули', ($j2['data']['counts']['operations'] ?? -1) === 0 && ($j2['data']['counts']['doors'] ?? -1) === 0);

echo "\n== Экспорт CSV / XLSX ==\n";
function csvRows(string $body): array
{
    return array_map(fn($l) => str_getcsv($l, ';', '"', ''), preg_split('/\r\n|\n|\r/', trim(substr($body, 3))));
}
[$c, $body, $h] = $director->raw('GET', '/export/csv?dataset=works&status=all&' . $qs);
$rows = $c === 200 ? csvRows($body) : [];
check('CSV работ: 200, text/csv, вложение, UTF-8 с BOM', $c === 200 && str_contains($h['content-type'][0] ?? '', 'text/csv') && str_contains($h['content-disposition'][0] ?? '', 'attachment') && str_starts_with($body, "\xEF\xBB\xBF"), $c . ' ' . ($h['content-type'][0] ?? ''));
check('CSV работ: заголовок + 4 записи (с аннулированной) + строка итогов', count($rows) === 6 && $rows[0][0] === '№ работы' && $rows[5][0] === 'ИТОГО', count($rows) . ' строк');
check('CSV работ: аннулированная помечена и не входит в итог; итог 770,00 (десятичная запятая)', ($rows[1][9] ?? '') === 'Аннулирована' && ($rows[5][8] ?? '') === '770,00', json_encode($rows[5] ?? null));
[$c, $body] = $director->raw('GET', '/export/csv?dataset=works&status=all&decimal=dot&' . $qs);
check('CSV с decimal=dot: точка как разделитель', str_contains($body, '250.00') && !str_contains($body, '250,00'));
[$c, $body] = $director->raw('GET', '/export/csv?dataset=works&status=voided&' . $qs);
check('фильтр статуса учитывается: только аннулированные (1 запись)', count(csvRows($body)) === 3, (string) count(csvRows($body)));
[$c, $body] = $director->raw('GET', '/export/csv?dataset=works&status=all&user_id=' . $m3id . '&work_type_id=999999&date_from=' . $today . '&date_to=' . $today);
check('фильтр по виду работ учитывается: пустая выгрузка (только заголовок)', $c === 200 && count(csvRows($body)) === 1);
[$c, $body] = $director->raw('GET', '/export/csv?dataset=payments&status=all&' . $qs);
$rows = csvRows($body);
check('CSV выплат: 4 выплаты (2 подтверждённые, 2 отменённые), итог только по подтверждённым 450,00', count($rows) === 6 && ($rows[5][3] ?? '') === '450,00', count($rows) . ' ' . json_encode($rows[5] ?? null));
[$c, $body] = $director->raw('GET', '/export/csv?dataset=payments&' . $qs);
check('CSV выплат по умолчанию: только подтверждённые (2)', count(csvRows($body)) === 4);
[$c, $body] = $director->raw('GET', '/export/csv?dataset=balances&' . $qs);
$rows = csvRows($body);
check('CSV остатков совпадает с финансами: 770,00 / 450,00 / -350,00 / -30,00 / долг 0,00 / переплата 30,00', ($rows[1][1] ?? '') === '770,00' && ($rows[1][2] ?? '') === '450,00' && ($rows[1][3] ?? '') === '-350,00' && ($rows[1][4] ?? '') === '-30,00' && ($rows[1][5] ?? '') === '0,00' && ($rows[1][6] ?? '') === '30,00', json_encode($rows[1] ?? null));
[$c, $body] = $director->raw('GET', '/export/csv?dataset=stats-daily&' . $qs);
$rows = csvRows($body);
check('CSV по дням: 3 работы, 770,00', ($rows[1][1] ?? '') === '3' && ($rows[1][6] ?? '') === '770,00', json_encode($rows[1] ?? null));
foreach (['stats-users', 'stats-types', 'stats-doors'] as $ds) {
    [$c, $body] = $director->raw('GET', "/export/csv?dataset={$ds}&{$qs}");
    check("CSV {$ds}: 200 и итоговая строка", $c === 200 && count(csvRows($body)) >= 3, (string) $c);
}
[$c] = $director->raw('GET', '/export/csv?dataset=bogus');
check('неизвестный набор данных -> 422', $c === 422);
[$c] = $director->raw('GET', '/export/csv?dataset=works&status=bogus');
check('неизвестный статус -> 422', $c === 422);
[$c] = $master3->raw('GET', '/export/csv?dataset=works');
check('исполнитель не может выгружать CSV -> 403', $c === 403);
[$c] = (new Client())->raw('GET', '/export/csv?dataset=works');
check('выгрузка без входа -> 401', $c === 401);

[$c, $body, $h] = $director->raw('GET', '/export/xlsx?dataset=works&status=all&' . $qs);
check('XLSX: 200, правильный MIME, вложение, формат ZIP', $c === 200 && str_contains($h['content-type'][0] ?? '', 'spreadsheetml.sheet') && str_contains($h['content-disposition'][0] ?? '', '.xlsx') && str_starts_with($body, 'PK'), $c . ' ' . ($h['content-type'][0] ?? ''));
$tmp = tempnam(sys_get_temp_dir(), 'smoke_x_');
file_put_contents($tmp, $body);
try {
    $wb = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
    $ws = $wb->getSheet(0);
    $sumActive = 0.0;
    for ($r = 2; $r <= 5; $r++) {
        if ($ws->getCell("J{$r}")->getValue() === 'Действует') {
            $sumActive += (float) $ws->getCell("I{$r}")->getValue();
        }
    }
    check('XLSX работ: заголовок, 4 записи и строка итогов', $ws->getCell('A1')->getValue() === '№ работы' && $ws->getHighestRow() === 6 && $ws->getCell('A6')->getValue() === 'ИТОГО', 'строк ' . $ws->getHighestRow());
    check('XLSX: суммы — настоящие числа, итог 770.00 равен сумме действующих работ', abs($sumActive - 770.0) < 0.001 && abs((float) $ws->getCell('I6')->getValue() - 770.0) < 0.001 && $ws->getCell('I3')->getDataType() === 'n', (string) $sumActive);
    check('XLSX: дата — настоящая дата Excel, а не текст', $ws->getCell('B2')->getDataType() === 'n' && str_contains($ws->getStyle('B2')->getNumberFormat()->getFormatCode(), 'yyyy'));
    check('XLSX: на листе «Параметры» указан период и фильтры', $wb->getSheetCount() === 2 && str_contains((string) $wb->getSheet(1)->getCell('C1')->getValue() . (string) $wb->getSheet(1)->getCell('A3')->getValue(), 'Период'));
} finally {
    @unlink($tmp);
}
[$c, $body] = $director->raw('GET', '/export/xlsx?dataset=works&status=all&work_type_id=999999&' . $qs);
$tmp = tempnam(sys_get_temp_dir(), 'smoke_x_');
file_put_contents($tmp, $body);
$wb2 = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
@unlink($tmp);
check('XLSX с фильтром без совпадений: только заголовок', $c === 200 && $wb2->getSheet(0)->getHighestRow() === 1);
[$c] = $master3->raw('GET', '/export/xlsx?dataset=works');
check('исполнитель не может выгружать XLSX -> 403', $c === 403);

echo "\n== Экспорт PDF ==\n";
$isPdf = fn(int $c, string $body, array $h): bool => $c === 200 && str_starts_with($body, '%PDF-') && str_contains(substr($body, -64), '%%EOF')
    && str_contains($body, 'DejaVuSans') && strlen($body) > 3000 && str_contains($h['content-type'][0] ?? '', 'application/pdf') && str_contains($h['content-disposition'][0] ?? '', '.pdf');
foreach ([['производственный отчёт', "/export/pdf/production?{$qs}"], ['отчёт по выплатам', "/export/pdf/payments?{$qs}&status=all"],
          ['общий финансовый отчёт', "/export/pdf/finance?{$qs}"], ['производственный отчёт за период-пресет', "/export/pdf/production?period=this_month"]] as [$nm, $url]) {
    [$c, $body, $h] = $director->raw('GET', $url);
    check("PDF «{$nm}»: корректный PDF, встроен шрифт с кириллицей, имя файла .pdf", $isPdf($c, $body, $h), $c . ' ' . strlen($body) . ' ' . ($c !== 200 ? substr($body, 0, 400) : ''));
}
[$c, $body, $h] = $director->raw('GET', "/export/pdf/photos?user_id={$masterId}&date_from={$today}&date_to={$today}");
check('PDF фотоотчёт: работы с фото, файл заметно больше текстового (встроены снимки)', $isPdf($c, $body, $h) && strlen($body) > 20000, $c . ' ' . strlen($body) . ' ' . ($c !== 200 ? substr($body, 0, 150) : ''));
[$c, $body, $h] = $director->raw('GET', '/export/pdf/photos?door_number=D-999999');
check('PDF фотоотчёт по несуществующей двери: пустой отчёт, а не ошибка', $isPdf($c, $body, $h));
[$c, $body, $h] = $owner->raw('GET', "/export/pdf/payroll/{$run2}");
check('расчётный лист: администратор получает PDF', $isPdf($c, $body, $h), $c . ' ' . substr($body, 0, 120));
[$c, $body, $h] = $master3->raw('GET', "/export/pdf/payroll/{$run2}");
check('расчётный лист: исполнитель получает свой подтверждённый расчёт', $isPdf($c, $body, $h), (string) $c);
[$c, $body, $h] = $director->raw('GET', "/export/pdf/payroll/{$run2}");
check('расчётный лист: директор получает подтверждённый расчёт', $isPdf($c, $body, $h), (string) $c);
[$c] = $master->raw('GET', "/export/pdf/payroll/{$run2}");
check('расчётный лист чужого исполнителя -> 404', $c === 404, (string) $c);
[$c] = $owner->raw('GET', "/export/pdf/payroll/{$run1}");
[$c2] = $master3->raw('GET', "/export/pdf/payroll/{$run1}");
check('отменённый расчёт не выдаётся никому -> 404', $c === 404 && $c2 === 404, "$c / $c2");
[$c] = (new Client())->raw('GET', "/export/pdf/payroll/{$run2}");
check('расчётный лист без входа -> 401', $c === 401);
[$c] = $master3->raw('GET', "/export/pdf/production?{$qs}");
check('исполнитель не может формировать производственный отчёт -> 403', $c === 403);
[$c] = $director->raw('GET', '/export/pdf/production?period=next_year');
check('PDF с некорректным периодом -> 422', $c === 422);
[$c] = $director->raw('GET', '/export/pdf/payments?status=bogus');
check('PDF выплат с неизвестным статусом -> 422', $c === 422);
$audit2 = Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'report.export' AND ip_address IS NOT NULL")->fetchColumn();
check('выгрузки записаны в журнал аудита', (int) $audit2 >= 14, (string) $audit2);

echo "\nИтого: " . ($checks - $fails) . " из {$checks} проверок пройдено.\n";
if ($fails === 0) {
    echo "Всё в порядке. Тестовые данные можно удалить: SMOKE_CLEANUP=1 php bin/smoke.php\n";
}
exit($fails === 0 ? 0 : 1);
