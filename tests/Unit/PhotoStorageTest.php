<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PhotoStorage;
use PHPUnit\Framework\TestCase;

final class PhotoStorageTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/door_ps_' . bin2hex(random_bytes(4));
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->base);
    }

    private function staged(string $content = 'original-bytes'): array
    {
        $o = tempnam(sys_get_temp_dir(), 'ps_o');
        $w = tempnam(sys_get_temp_dir(), 'ps_w');
        file_put_contents($o, $content);
        file_put_contents($w, 'optimized');
        return ['original_tmp' => $o, 'optimized_tmp' => $w, 'ext' => 'png', 'sha256' => hash('sha256', $content)];
    }

    public function testSanitizeName(): void
    {
        $this->assertSame('shot.jpg', PhotoStorage::sanitizeName('shot.jpg'));
        $this->assertSame('passwd', PhotoStorage::sanitizeName('../../etc/passwd'));
        $this->assertSame('evil.png', PhotoStorage::sanitizeName('C:\\Users\\x\\evil.png'));
        $this->assertSame('photo', PhotoStorage::sanitizeName("\n\t"));
        $this->assertSame('ab.jpg', PhotoStorage::sanitizeName("a\x00b.jpg"));
        $this->assertSame(255, mb_strlen(PhotoStorage::sanitizeName(str_repeat('я', 400))));
    }

    public function testPlaceCreatesFilesWithRandomNamesAndDiscardRemovesThem(): void
    {
        $storage = new PhotoStorage($this->base);
        $a = $this->staged('first');
        $b = $this->staged('second');
        $placed = $storage->place([$a, $b]);

        $this->assertCount(2, $placed);
        $this->assertNotSame($placed[0]['file_name'], $placed[1]['file_name']);
        foreach ($placed as $p) {
            $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f]{32}\.png$#', $p['file_name']);
            $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/[0-9a-f]{32}_opt\.jpg$#', $p['optimized_file_name']);
            $this->assertFileExists($this->base . '/' . $p['file_name']);
            $this->assertFileExists($this->base . '/' . $p['optimized_file_name']);
            $this->assertSame($p['sha256'], hash_file('sha256', $this->base . '/' . $p['file_name']));
        }
        $this->assertNotNull($storage->resolve($placed[0]['file_name']));

        $storage->discard($placed);
        foreach ($placed as $p) {
            $this->assertFileDoesNotExist($this->base . '/' . $p['file_name']);
            $this->assertFileDoesNotExist($this->base . '/' . $p['optimized_file_name']);
        }
        $storage->discardStaged([$a, $b]);
        $this->assertFileDoesNotExist($a['optimized_tmp']);
        @unlink($a['original_tmp']);
        @unlink($b['original_tmp']);
    }

    public function testPlaceRejectsCorruptedCopy(): void
    {
        $storage = new PhotoStorage($this->base);
        $s = $this->staged('abc');
        $s['sha256'] = str_repeat('0', 64);          // контрольная сумма не совпадёт
        try {
            $storage->place([$s]);
            $this->fail('Ожидалось исключение');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Контрольная сумма', $e->getMessage());
        }
        $left = iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS)));
        $files = array_filter(iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS))), fn($f) => $f->isFile());
        $this->assertCount(0, $files, 'после ошибки не должно остаться файлов');
        $storage->discardStaged([$s]);
        @unlink($s['original_tmp']);
    }

    public function testResolveBlocksPathTraversalAndMissingFiles(): void
    {
        $storage = new PhotoStorage($this->base);
        file_put_contents($this->base . '/ok.jpg', 'x');
        file_put_contents(dirname($this->base) . '/outside_' . basename($this->base) . '.txt', 'secret');
        $this->assertNotNull($storage->resolve('ok.jpg'));
        foreach (['../outside_' . basename($this->base) . '.txt', '/etc/passwd', 'a/../ok.jpg', "ok.jpg\0.png", 'no\\such', 'missing.jpg', ''] as $bad) {
            $this->assertNull($storage->resolve($bad), $bad);
        }
        @unlink(dirname($this->base) . '/outside_' . basename($this->base) . '.txt');
    }
}
