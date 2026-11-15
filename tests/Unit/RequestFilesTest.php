<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestFilesTest extends TestCase
{
    public function testNormalizesArrayOfFiles(): void
    {
        $files = ['photos' => [
            'name' => ['a.jpg', 'b.png'], 'tmp_name' => ['/tmp/a', '/tmp/b'], 'size' => [10, 20], 'error' => [0, 0],
        ]];
        $out = Request::normalizeFiles($files);
        $this->assertCount(2, $out);
        $this->assertSame(['name' => 'b.png', 'tmp_name' => '/tmp/b', 'size' => 20, 'error' => 0], $out[1]);
    }

    public function testNormalizesSingleFileAndDropsEmptyField(): void
    {
        $out = Request::normalizeFiles([
            'photo'  => ['name' => 'x.jpg', 'tmp_name' => '/tmp/x', 'size' => 5, 'error' => 0],
            'photos' => ['name' => [''], 'tmp_name' => [''], 'size' => [0], 'error' => [UPLOAD_ERR_NO_FILE]],
        ]);
        $this->assertCount(1, $out);
        $this->assertSame('x.jpg', $out[0]['name']);
    }

    public function testIgnoresUnknownFieldsAndKeepsUploadErrors(): void
    {
        $out = Request::normalizeFiles([
            'avatar' => ['name' => 'z.jpg', 'tmp_name' => '/tmp/z', 'size' => 1, 'error' => 0],
            'photo'  => ['name' => 'big.jpg', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE],
        ]);
        $this->assertCount(1, $out);
        $this->assertSame(UPLOAD_ERR_INI_SIZE, $out[0]['error']);
    }

    public function testMultipartDetection(): void
    {
        $r = new Request('POST', '/x', [], ['content-type' => 'multipart/form-data; boundary=abc']);
        $this->assertTrue($r->isMultipart());
        $this->assertFalse((new Request('POST', '/x', [], ['content-type' => 'application/json']))->isMultipart());
    }
}
