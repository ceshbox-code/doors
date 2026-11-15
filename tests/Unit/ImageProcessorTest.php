<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Helpers\ImageProcessor;
use PHPUnit\Framework\TestCase;

final class ImageProcessorTest extends TestCase
{
    public function testOrientationTableMatchesExifSpec(): void
    {
        $this->assertSame(['flip' => null, 'rotate_ccw' => 0],   ImageProcessor::orientationOps(1));
        $this->assertSame(['flip' => 'h',  'rotate_ccw' => 0],   ImageProcessor::orientationOps(2));
        $this->assertSame(['flip' => null, 'rotate_ccw' => 180], ImageProcessor::orientationOps(3));
        $this->assertSame(['flip' => 'v',  'rotate_ccw' => 0],   ImageProcessor::orientationOps(4));
        $this->assertSame(['flip' => 'h',  'rotate_ccw' => 90],  ImageProcessor::orientationOps(5));
        $this->assertSame(['flip' => null, 'rotate_ccw' => 270], ImageProcessor::orientationOps(6));   // 90° по часовой
        $this->assertSame(['flip' => 'h',  'rotate_ccw' => 270], ImageProcessor::orientationOps(7));
        $this->assertSame(['flip' => null, 'rotate_ccw' => 90],  ImageProcessor::orientationOps(8));   // 270° по часовой
    }

    public function testUnknownOrientationIsNoop(): void
    {
        foreach ([0, 9, -1, 255] as $o) {
            $this->assertSame(['flip' => null, 'rotate_ccw' => 0], ImageProcessor::orientationOps($o));
        }
    }

    public function testLimitsMatchTz(): void
    {
        $this->assertSame(15 * 1024 * 1024, ImageProcessor::MAX_BYTES);
        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], array_keys(ImageProcessor::TYPES));
    }

    public function testInspectRejectsEmptyAndNonImages(): void
    {
        $empty = tempnam(sys_get_temp_dir(), 'ut_');
        $text = tempnam(sys_get_temp_dir(), 'ut_');
        file_put_contents($text, "<?php echo 'hi';");
        try {
            foreach ([$empty, $text] as $f) {
                try {
                    ImageProcessor::inspect($f);
                    $this->fail('Ожидалась ApiException');
                } catch (\App\Core\ApiException $e) {
                    $this->assertSame('PHOTO_INVALID', $e->errorCode);
                    $this->assertSame(422, $e->httpStatus);
                }
            }
        } finally {
            @unlink($empty);
            @unlink($text);
        }
    }
}
