<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\{ApiException, Request, Response, Router};
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/api/v1/doors/{id}', fn(Request $req) => Response::ok(['id' => $req->params['id']]));
        return $r;
    }

    public function testMatchesRouteAndExtractsParams(): void
    {
        $res = $this->router()->dispatch(new Request('GET', '/api/v1/doors/42'));
        $this->assertSame(200, $res->status);
        $this->assertSame('42', $res->body['data']['id']);
    }

    public function testUnknownPathReturns404InTzFormat(): void
    {
        $res = $this->router()->dispatch(new Request('GET', '/api/v1/nope'));
        $this->assertSame(404, $res->status);
        $this->assertFalse($res->body['success']);
        $this->assertSame('NOT_FOUND', $res->body['error']['code']);
    }

    public function testWrongMethodReturns405(): void
    {
        $res = $this->router()->dispatch(new Request('POST', '/api/v1/doors/1'));
        $this->assertSame(405, $res->status);
    }

    public function testApiExceptionBecomesTzFormattedError(): void
    {
        $r = new Router();
        $r->post('/x', function (Request $req): Response {
            throw new ApiException('FORBIDDEN', 'Нет прав', 403, ['field' => 'msg']);
        });
        $res = $r->dispatch(new Request('POST', '/x'));
        $this->assertSame(403, $res->status);
        $this->assertSame('FORBIDDEN', $res->body['error']['code']);
        $this->assertSame(['field' => 'msg'], $res->body['error']['details']);
    }
}
