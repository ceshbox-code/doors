<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\AuthService;

final class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function captcha(Request $request): Response
    {
        Session::start();

        $a = random_int(2, 9);
        $b = random_int(1, 9);
        $id = bin2hex(random_bytes(16));

        $_SESSION['login_captcha'] = [
            'id' => $id,
            'answer' => hash('sha256', (string) ($a + $b)),
            'expires_at' => time() + 300,
        ];

        return Response::ok([
            'id' => $id,
            'question' => "Сколько будет {$a} + {$b}?",
            'expires_in' => 300,
        ]);
    }

    public function login(Request $request): Response
    {
        $b = $request->json();
        $this->verifyCaptcha($b);
        $data = $this->auth->login((string) ($b['username'] ?? ''), (string) ($b['password'] ?? ''), $request->ip);
        return Response::ok($data);
    }

    private function verifyCaptcha(array $body): void
    {
        Session::start();
        $challenge = $_SESSION['login_captcha'] ?? null;
        unset($_SESSION['login_captcha']);

        $id = (string) ($body['captcha_id'] ?? '');
        $answer = trim((string) ($body['captcha_answer'] ?? ''));

        if (
            !is_array($challenge)
            || !isset($challenge['id'], $challenge['answer'], $challenge['expires_at'])
            || (int) $challenge['expires_at'] < time()
            || $id === ''
            || !hash_equals((string) $challenge['id'], $id)
            || $answer === ''
            || !hash_equals((string) $challenge['answer'], hash('sha256', $answer))
        ) {
            throw new App\Core\ApiException('CAPTCHA_INVALID', 'Неверный или просроченный проверочный пример. Обновите CAPTCHA.', 422);
        }
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request->user, $request->ip);
        return Response::ok(null);
    }

    public function me(Request $request): Response
    {
        return Response::ok($this->auth->payload($request->user));
    }
}
