<?php

namespace Microscrap\GFX\GLFW;

use Fabricate\Contracts\Framebuffers\Framebuffer;
use Fabricate\Contracts\Rendering\RenderingException;
use Fabricate\Framebuffers\FormatSpec;
use Fabricate\NutsAndBolts\Concerns\Splices16Bits;
use Fabricate\Rendering\Concerns\GFXText;
use Fabricate\Rendering\Renderer2D;
use Microscrap\Bindings\GLFW\DataObjects\GlfwWindow;
use Microscrap\Bindings\GLFW\Enums\ClearBufferMask;
use Microscrap\Bindings\GLFW\Enums\EnableCap;

/**
 * GLFW/OpenGL renderer. Window context is owned by GLFWWindow.
 *
 * Shapes are rasterized with GL scissor + clear (available in the minimal
 * glfw GL helper set) — same logical draw API as SDL3Gfx for the smoke path.
 *
 * Class name is GLFWGfx (not GlfwGfx) — on macOS APFS those collide.
 */
class GLFWGfx extends Renderer2D
{
    use GFXText;
    use Splices16Bits;

    protected ?Framebuffer $buffer = null;

    protected ?GlfwWindow $native_window = null;

    /** Logical drawing size (window points) — matches Display/Adafruit coords. */
    protected int $viewport_width = 0;

    protected int $viewport_height = 0;

    /** Physical GL drawable size (Retina often 2× window size on macOS). */
    protected int $framebuffer_width = 0;

    protected int $framebuffer_height = 0;

    protected float $content_scale_x = 1.0;

    protected float $content_scale_y = 1.0;

    public function __construct() {}

    public function useNativeWindow(GlfwWindow $window, int $width, int $height): static
    {
        $this->native_window = $window;
        $this->viewport_width = $width;
        $this->viewport_height = $height;
        glfwMakeContextCurrent($window);
        $this->syncDrawableSize();

        return $this;
    }

    public function useFramebuffer(Framebuffer $framebuffer): static
    {
        if (! $this->supportsFramebuffer($framebuffer)) {
            throw GlfwGfxException::unsupportedFramebuffer($framebuffer::class);
        }

        $this->buffer = $framebuffer;
        $this->viewport_width = $framebuffer->viewportWidth();
        $this->viewport_height = $framebuffer->viewportHeight();
        $this->framebuffer_width = $this->viewport_width;
        $this->framebuffer_height = $this->viewport_height;
        $this->content_scale_x = 1.0;
        $this->content_scale_y = 1.0;

        return $this;
    }

    public function supportsFramebuffer(Framebuffer $framebuffer): bool
    {
        return $framebuffer instanceof GLFWOpenGLFramebuffer;
    }

    /**
     * Align glViewport with the real pixel framebuffer. On macOS Retina,
     * window size is in points (e.g. 800×600) while the drawable is 2× —
     * using window size for the viewport shrinks content into the lower-left.
     */
    public function syncDrawableSize(): static
    {
        $window = $this->requireNativeWindow();
        glfwMakeContextCurrent($window);

        $fb = glfwGetFramebufferSize($window);
        $this->framebuffer_width = max(1, (int) ($fb['width'] ?? $this->viewport_width));
        $this->framebuffer_height = max(1, (int) ($fb['height'] ?? $this->viewport_height));

        $scale = glfwGetWindowContentScale($window);
        $this->content_scale_x = max(0.0001, (float) ($scale['xscale'] ?? 1.0));
        $this->content_scale_y = max(0.0001, (float) ($scale['yscale'] ?? 1.0));

        // Prefer explicit fb/window ratio when sizes are known.
        if ($this->viewport_width > 0 && $this->viewport_height > 0) {
            $this->content_scale_x = $this->framebuffer_width / $this->viewport_width;
            $this->content_scale_y = $this->framebuffer_height / $this->viewport_height;
        }

        glViewport(0, 0, $this->framebuffer_width, $this->framebuffer_height);

        return $this;
    }

    public function buffer(): Framebuffer
    {
        return $this->requireBuffer();
    }

    public function render(): array
    {
        $buffer = $this->requireBuffer();

        if (! method_exists($buffer, 'dump')) {
            throw RenderingException::framebufferNotAttached(static::class);
        }

        return $buffer->dump();
    }

    public static function preferredFramebuffer(FormatSpec $format_spec, int $width, int $height): Framebuffer
    {
        return new GLFWOpenGLFramebuffer($format_spec, $width, $height);
    }

    public function width(): int
    {
        return $this->viewport_width;
    }

    public function height(): int
    {
        return $this->viewport_height;
    }

    public function fill(int $color): static
    {
        if ($this->buffer instanceof GLFWOpenGLFramebuffer) {
            $this->buffer->clear($color);

            return $this;
        }

        $this->requireNativeWindow();
        $this->applyClearColor($color);
        glDisable(EnableCap::GL_SCISSOR_TEST);
        glClear(ClearBufferMask::GL_COLOR_BUFFER_BIT);

        return $this;
    }

    public function present(): static
    {
        if ($this->buffer instanceof GLFWOpenGLFramebuffer) {
            $this->buffer->present();

            return $this;
        }

        $window = $this->requireNativeWindow();
        glfwMakeContextCurrent($window);
        $this->syncDrawableSize();
        glfwSwapBuffers($window);
        glfwPollEvents();

        return $this;
    }

    public function drawPixel(int $x, int $y, int $color): static
    {
        return $this->fillRect($x, $y, 1, 1, $color);
    }

    /**
     * @param  array<int, array{0: int, 1: int, 2: int}>  $pixels
     */
    public function drawPixels(array $pixels): static
    {
        foreach ($pixels as [$x, $y, $color]) {
            $this->drawPixel($x, $y, $color);
        }

        return $this;
    }

    public function drawLine(int $x0, int $y0, int $x1, int $y1, int $color): static
    {
        // Bresenham via 1×1 fills — fine for smoke / thin UI lines.
        $dx = abs($x1 - $x0);
        $sx = ($x0 < $x1) ? 1 : -1;
        $dy = -abs($y1 - $y0);
        $sy = ($y0 < $y1) ? 1 : -1;
        $err = $dx + $dy;

        while (true) {
            $this->drawPixel($x0, $y0, $color);

            if ($x0 === $x1 && $y0 === $y1) {
                break;
            }

            $e2 = 2 * $err;

            if ($e2 >= $dy) {
                $err += $dy;
                $x0 += $sx;
            }

            if ($e2 <= $dx) {
                $err += $dx;
                $y0 += $sy;
            }
        }

        return $this;
    }

    public function drawHorizontalLine(int $x, int $y, int $w, int $color): static
    {
        return $this->fillRect($x, $y, $w, 1, $color);
    }

    public function drawVerticalLine(int $x, int $y, int $h, int $color): static
    {
        return $this->fillRect($x, $y, 1, $h, $color);
    }

    /**
     * @param  array<int, array{0: int, 1: int, 2: int, 3: int, 4: int}>  $lines
     */
    public function drawLines(array $lines): static
    {
        foreach ($lines as [$x0, $y0, $x1, $y1, $color]) {
            $this->drawLine($x0, $y0, $x1, $y1, $color);
        }

        return $this;
    }

    public function drawRect(int $x, int $y, int $w, int $h, int $color): static
    {
        $this->drawHorizontalLine($x, $y, $w, $color);
        $this->drawHorizontalLine($x, ($y + $h) - 1, $w, $color);
        $this->drawVerticalLine($x, $y, $h, $color);

        return $this->drawVerticalLine(($x + $w) - 1, $y, $h, $color);
    }

    public function fillRect(int $x, int $y, int $w, int $h, int $color): static
    {
        if ($this->buffer instanceof GLFWOpenGLFramebuffer) {
            $this->buffer->fillRectRaw($x, $y, $w, $h, $color);

            return $this;
        }

        $this->requireNativeWindow();

        if ($w <= 0 || $h <= 0) {
            return $this;
        }

        // Clip in logical (window-point) space — same coords as SDL/Adafruit.
        $left = max(0, $x);
        $top = max(0, $y);
        $right = min($this->viewport_width, $x + $w);
        $bottom = min($this->viewport_height, $y + $h);
        $cw = $right - $left;
        $ch = $bottom - $top;

        if ($cw <= 0 || $ch <= 0) {
            return $this;
        }

        // Scale logical → physical framebuffer pixels (Retina 2×, etc.).
        $px = (int) round($left * $this->content_scale_x);
        $py = (int) round($top * $this->content_scale_y);
        $pw = max(1, (int) round($cw * $this->content_scale_x));
        $ph = max(1, (int) round($ch * $this->content_scale_y));

        // OpenGL scissor origin is bottom-left of the *framebuffer*.
        $gl_y = $this->framebuffer_height - $py - $ph;

        $this->applyClearColor($color);
        glEnable(EnableCap::GL_SCISSOR_TEST);
        glScissor($px, $gl_y, $pw, $ph);
        glClear(ClearBufferMask::GL_COLOR_BUFFER_BIT);
        glDisable(EnableCap::GL_SCISSOR_TEST);

        return $this;
    }

    public function drawRoundRect(int $x, int $y, int $w, int $h, int $r, int $color): static
    {
        return $this->drawRect($x, $y, $w, $h, $color);
    }

    public function fillRoundRect(int $x, int $y, int $w, int $h, int $r, int $color): static
    {
        return $this->fillRect($x, $y, $w, $h, $color);
    }

    public function drawCircle(int $x0, int $y0, int $r, int $color): static
    {
        return $this->fillCircle($x0, $y0, $r, $color);
    }

    public function fillCircle(int $x0, int $y0, int $r, int $color): static
    {
        if ($r < 0) {
            return $this;
        }

        if (($x0 + $r < 0) || ($x0 - $r >= $this->width()) ||
            ($y0 + $r < 0) || ($y0 - $r >= $this->height())) {
            return $this;
        }

        // Midpoint circle: horizontal spans (same idea as Adafruit fillCircle).
        $f = 1 - $r;
        $ddf_x = 1;
        $ddf_y = -2 * $r;
        $x = 0;
        $y = $r;

        $this->drawVerticalLine($x0, $y0 - $r, 2 * $r + 1, $color);

        while ($x < $y) {
            if ($f >= 0) {
                $y--;
                $ddf_y += 2;
                $f += $ddf_y;
            }

            $x++;
            $ddf_x += 2;
            $f += $ddf_x;

            $this->drawVerticalLine($x0 + $x, $y0 - $y, 2 * $y + 1, $color);
            $this->drawVerticalLine($x0 - $x, $y0 - $y, 2 * $y + 1, $color);
            $this->drawVerticalLine($x0 + $y, $y0 - $x, 2 * $x + 1, $color);
            $this->drawVerticalLine($x0 - $y, $y0 - $x, 2 * $x + 1, $color);
        }

        return $this;
    }

    public function drawEllipse(int $x0, int $y0, int $rw, int $rh, int $color): static
    {
        return $this->fillEllipse($x0, $y0, $rw, $rh, $color);
    }

    public function fillEllipse(int $x0, int $y0, int $rw, int $rh, int $color): static
    {
        return $this->fillCircle($x0, $y0, max($rw, $rh), $color);
    }

    public function drawTriangle(int $x0, int $y0, int $x1, int $y1, int $x2, int $y2, int $color): static
    {
        $this->drawLine($x0, $y0, $x1, $y1, $color);
        $this->drawLine($x1, $y1, $x2, $y2, $color);

        return $this->drawLine($x2, $y2, $x0, $y0, $color);
    }

    public function fillTriangle(int $x0, int $y0, int $x1, int $y1, int $x2, int $y2, int $color): static
    {
        return $this->drawTriangle($x0, $y0, $x1, $y1, $x2, $y2, $color);
    }

    protected function applyClearColor(int $color): void
    {
        [$r, $g, $b, $a] = $this->unpackRgba($color);
        glClearColor($r / 255.0, $g / 255.0, $b / 255.0, $a / 255.0);
    }

    protected function requireBuffer(): Framebuffer
    {
        if (is_null($this->buffer)) {
            throw RenderingException::framebufferNotAttached(static::class);
        }

        return $this->buffer;
    }

    protected function requireNativeWindow(): GlfwWindow
    {
        if (is_null($this->native_window)) {
            throw new GlfwGfxException('GLFWGfx has no native window — call useNativeWindow() after window boot.');
        }

        return $this->native_window;
    }

    /**
     * Unpack RRGGBBAA via {@see Splices16Bits}.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    protected function unpackRgba(int $color): array
    {
        $rg = $this->splitBytes($color >> 16);
        $ba = $this->splitBytes($color);

        return [$rg['high'], $rg['low'], $ba['high'], $ba['low']];
    }
}
