<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Services\UserService;

final class UserController
{
    private UserService $users;

    public function __construct()
    {
        $this->users = new UserService();
    }

    private static function id(Request $r): int
    {
        $raw = (string) ($r->params['id'] ?? '');
        if (!ctype_digit($raw)) {
            throw new ApiException('NOT_FOUND', 'Пользователь не найден', 404);
        }
        return (int) $raw;
    }

    public function index(Request $r): Response
    {
        return Response::ok($this->users->list($r->query));
    }

    public function store(Request $r): Response
    {
        return Response::ok($this->users->create($r->user, $r->json(), $r->ip), 201);
    }

    public function show(Request $r): Response
    {
        return Response::ok($this->users->get(self::id($r)));
    }

    public function update(Request $r): Response
    {
        return Response::ok($this->users->update($r->user, self::id($r), $r->json(), $r->ip));
    }

    public function deactivate(Request $r): Response
    {
        return Response::ok($this->users->setActive($r->user, self::id($r), false, $r->ip));
    }

    public function activate(Request $r): Response
    {
        return Response::ok($this->users->setActive($r->user, self::id($r), true, $r->ip));
    }
}
