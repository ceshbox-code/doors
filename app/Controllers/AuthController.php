<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

final class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function login(Request $request): Response
    {
        $b = $request->json();
        $data = $this->auth->login((string) ($b['username'] ?? ''), (string) ($b['password'] ?? ''), $request->ip);
        return Response::ok($data);
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
