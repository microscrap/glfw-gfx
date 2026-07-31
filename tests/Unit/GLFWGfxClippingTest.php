<?php

namespace DeptOfScrapyardRobotics\Tests\Unit;

use Fabricate\Contracts\Framebuffers\Enums\BitDepth;
use Fabricate\Contracts\Framebuffers\Enums\Endianness;
use Fabricate\Contracts\Framebuffers\Enums\PixelFormat;
use Fabricate\Framebuffers\FormatSpec;
use Fabricate\Framebuffers\Strategy\FullFramebuffer;
use Fabricate\NutsAndBolts\Geometry\Rect;
use Microscrap\GFX\GLFW\GLFWGfx;
use Microscrap\GFX\GLFW\GlfwGfxException;
use PHPUnit\Framework\TestCase;

/**
 * GL rasterization needs a live window and context, so these cases cover the
 * part that is verifiable headless: that the clip is evaluated *before* either
 * backend path is entered.
 *
 * A bare renderer has neither a framebuffer nor a native window, so any call
 * that reaches the backend throws. That makes the exception a precise probe —
 * returning normally proves the clip short-circuited first, and throwing proves
 * the call would otherwise have reached the GL layer.
 */
class GLFWGfxClippingTest extends TestCase
{
    public function testAFullyClippedFillRectNeverReachesTheBackend(): void
    {
        $gfx = new GLFWGfx;
        $gfx->pushClip(new Rect(0, 0, 4, 4));
        $gfx->pushClip(new Rect(40, 40, 4, 4));

        $this->assertSame($gfx, $gfx->fillRect(0, 0, 64, 64, 0xFFFFFFFF));
    }

    public function testAnUnclippedFillRectDoesReachTheBackend(): void
    {
        $this->expectException(GlfwGfxException::class);
        $this->expectExceptionMessage('has no native window');

        (new GLFWGfx)->fillRect(0, 0, 64, 64, 0xFFFFFFFF);
    }

    public function testAFullyClippedDrawPixelNeverReachesTheBackend(): void
    {
        $gfx = new GLFWGfx;
        $gfx->pushClip(new Rect(10, 10, 4, 4));

        $this->assertSame($gfx, $gfx->drawPixel(0, 0, 0xFFFFFFFF));
    }

    public function testAFullyClippedFillNeverReachesTheBackend(): void
    {
        $gfx = new GLFWGfx;
        $gfx->pushClip(new Rect(0, 0, 4, 4));
        $gfx->pushClip(new Rect(40, 40, 4, 4));

        $this->assertSame($gfx, $gfx->fill(0xFFFFFFFF));
    }

    /**
     * fill() used to clear the whole surface natively and ignore the clip
     * entirely; it now routes through the rect path so the clip is honoured.
     */
    public function testAClippedFillIsRoutedThroughTheRectPath(): void
    {
        $gfx = new GLFWGfx;
        $gfx->pushClip(new Rect(8, 8, 8, 8));

        $this->expectException(GlfwGfxException::class);
        $this->expectExceptionMessage('has no native window');

        $gfx->fill(0xFFFFFFFF);
    }

    public function testNonPositiveRectsAreRejectedBeforeTheBackend(): void
    {
        $gfx = new GLFWGfx;

        $this->assertSame($gfx, $gfx->fillRect(0, 0, 0, 10, 0xFFFFFFFF));
        $this->assertSame($gfx, $gfx->fillRect(0, 0, 10, -4, 0xFFFFFFFF));
    }

    public function testTheClipStackNarrowsAndRestoresOnTheRenderer(): void
    {
        $gfx = new GLFWGfx;

        $this->assertNull($gfx->clip());

        $gfx->pushClip(new Rect(0, 0, 20, 20));
        $gfx->pushClip(new Rect(0, 0, 100, 100));

        $this->assertSame([0, 0, 19, 19], $gfx->clip()?->toBounds());

        $gfx->popClip();

        $this->assertSame([0, 0, 19, 19], $gfx->clip()?->toBounds());

        $gfx->popClip();

        $this->assertNull($gfx->clip());
    }

    public function testItOnlyAcceptsItsOwnFramebufferType(): void
    {
        $spec = new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, endianness: Endianness::MSB);
        $foreign = new FullFramebuffer(8, 8, $spec);

        $this->assertFalse((new GLFWGfx)->supportsFramebuffer($foreign));

        $this->expectException(GlfwGfxException::class);

        (new GLFWGfx)->useFramebuffer($foreign);
    }
}
