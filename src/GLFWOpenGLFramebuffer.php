<?php

namespace Microscrap\GFX\GLFW;

use Fabricate\Contracts\Framebuffers\DumpedBuffer;
use Fabricate\Contracts\Framebuffers\Enums\BitDepth;
use Fabricate\Contracts\Framebuffers\Enums\Endianness;
use Fabricate\Contracts\Framebuffers\Enums\PixelFormat;
use Fabricate\Contracts\Framebuffers\Enums\RenderType;
use Fabricate\Contracts\Framebuffers\FormatSpec;
use Fabricate\Contracts\Framebuffers\Framebuffer;
use Fabricate\Framebuffers\PixelGrid;
use Microscrap\Bindings\GLFW\DataObjects\GlfwWindow;
use Microscrap\Bindings\GLFW\Enums\ClearBufferMask;
use Microscrap\Bindings\GLFW\Enums\EnableCap;
use Microscrap\Bindings\GLFW\Enums\TrueFalse;
use Microscrap\Bindings\GLFW\Enums\WindowHint;
use ScrapyardIO\NutsAndBolts\Concerns\Splices16Bits;

/**
 * OpenGL-backed framebuffer for the GLFW window stack (strategy: glfw-ogl).
 *
 * ext-glfw's GL surface has no FBO/texture upload yet, so this mirrors
 * Sdl3Framebuffer::attachedTo semantically:
 * - logical PixelGrid for dump/getPixel (CPU shadow)
 * - dual-write into the window GL backbuffer via scissor+clear
 * - present() → glfwSwapBuffers
 *
 * Headless construction owns a hidden GLFW window for a GL context.
 */
class GLFWOpenGLFramebuffer implements Framebuffer
{
    use Splices16Bits;

    protected PixelGrid $grid;

    protected ?GlfwWindow $native_window = null;

    protected bool $owns_window = false;

    protected int $framebuffer_width = 0;

    protected int $framebuffer_height = 0;

    protected float $content_scale_x = 1.0;

    protected float $content_scale_y = 1.0;

    public function __construct(
        protected FormatSpec $format_spec,
        protected int $width,
        protected int $height,
        ?GlfwWindow $attach_to = null,
    ) {
        $this->grid = new PixelGrid($width, $height);

        if (! is_null($attach_to)) {
            $this->native_window = $attach_to;
            $this->owns_window = false;
            $this->bindContext();

            return;
        }

        $this->native_window = $this->createHiddenWindow($width, $height);
        $this->owns_window = true;
        $this->bindContext();
    }

    public function __destruct()
    {
        if ($this->owns_window && ! is_null($this->native_window)) {
            glfwDestroyWindow($this->native_window);
            $this->native_window = null;
        }
    }

    public static function attachedTo(
        GlfwWindow $window,
        FormatSpec $format_spec,
        int $width,
        int $height,
    ): static {
        return new static($format_spec, $width, $height, $window);
    }

    public static function rgbaSpec(): FormatSpec
    {
        return new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, endianness: Endianness::MSB);
    }

    public function formatSpec(): FormatSpec
    {
        return $this->format_spec;
    }

    public function nativeWindow(): ?GlfwWindow
    {
        return $this->native_window;
    }

    public function isHeadless(): bool
    {
        return $this->owns_window;
    }

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function syncDrawableSize(): static
    {
        $window = $this->requireWindow();
        glfwMakeContextCurrent($window);

        $fb = glfwGetFramebufferSize($window);
        $this->framebuffer_width = max(1, (int) ($fb['width'] ?? $this->width));
        $this->framebuffer_height = max(1, (int) ($fb['height'] ?? $this->height));
        $this->content_scale_x = $this->framebuffer_width / max(1, $this->width);
        $this->content_scale_y = $this->framebuffer_height / max(1, $this->height);

        glViewport(0, 0, $this->framebuffer_width, $this->framebuffer_height);

        return $this;
    }

    public function clear(int $color = 0): static
    {
        $this->grid->fill($color);
        $this->requireWindow();
        $this->applyClearColor($color);
        glDisable(EnableCap::GL_SCISSOR_TEST);
        glClear(ClearBufferMask::GL_COLOR_BUFFER_BIT);

        return $this;
    }

    public function fillRectRaw(int $x, int $y, int $width, int $height, int $color): static
    {
        if (($width <= 0) || ($height <= 0)) {
            return $this;
        }

        $left = max(0, $x);
        $top = max(0, $y);
        $right = min($this->width, $x + $width);
        $bottom = min($this->height, $y + $height);
        $cw = $right - $left;
        $ch = $bottom - $top;

        if ($cw <= 0 || $ch <= 0) {
            return $this;
        }

        for ($py = $top; $py < $bottom; $py++) {
            for ($px = $left; $px < $right; $px++) {
                $this->grid->set($px, $py, $color);
            }
        }

        $this->glFillRect($left, $top, $cw, $ch, $color);

        return $this;
    }

    public function point(int $x, int $y, int $color): static
    {
        return $this->fillRectRaw($x, $y, 1, 1, $color);
    }

    public function present(): static
    {
        $window = $this->requireWindow();
        glfwMakeContextCurrent($window);
        $this->syncDrawableSize();
        glfwSwapBuffers($window);
        glfwPollEvents();

        return $this;
    }

    public function getPixel(int $x, int $y): int
    {
        return $this->grid->contains($x, $y) ? $this->grid->get($x, $y) : 0;
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        if (! $this->grid->contains($x, $y)) {
            return $this;
        }

        $this->grid->set($x, $y, $value);
        $this->glFillRect($x, $y, 1, 1, $value);

        return $this;
    }

    /**
     * @param  array<int, array{0: int, 1: int, 2: int}>  $pixels
     */
    public function setPixels(array $pixels): static
    {
        foreach ($pixels as [$x, $y, $value]) {
            $this->setPixel($x, $y, $value);
        }

        return $this;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $coordinates
     */
    public function setRegion(array $coordinates, int $value): static
    {
        foreach ($coordinates as [$x, $y]) {
            $this->setPixel($x, $y, $value);
        }

        return $this;
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        return $this->fillRectRaw($x, $y, $width, $height, $color);
    }

    public function blitFrom(Framebuffer $source, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        for ($y = 0; $y < $source->viewportHeight(); $y++) {
            for ($x = 0; $x < $source->viewportWidth(); $x++) {
                $this->setPixel($offset_x + $x, $offset_y + $y, $source->getPixel($x, $y));
            }
        }

        return $this;
    }

    public function blitTo(Framebuffer $target, int $offset_x = 0, int $offset_y = 0): Framebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    /**
     * @return array<int, DumpedBuffer>
     */
    public function dump(): array
    {
        $bytes = [];

        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $word = $this->grid->get($x, $y);
                $bytes[] = ($word >> 24) & 0xFF;
                $bytes[] = ($word >> 16) & 0xFF;
                $bytes[] = ($word >> 8) & 0xFF;
                $bytes[] = $word & 0xFF;
            }
        }

        return [
            new DumpedBuffer(
                RenderType::FULL,
                static::rgbaSpec(),
                $bytes,
                width: $this->width,
                height: $this->height,
            ),
        ];
    }

    protected function bindContext(): void
    {
        $window = $this->requireWindow();
        glfwMakeContextCurrent($window);
        glfwSwapInterval(1);
        $this->syncDrawableSize();
    }

    protected function createHiddenWindow(int $width, int $height): GlfwWindow
    {
        if (! glfwInit()) {
            $error = glfwGetError();

            throw new GlfwGfxException(
                'glfwInit failed for headless glfw-ogl: '.($error['description'] ?? '')
            );
        }

        glfwDefaultWindowHints();
        glfwWindowHint(WindowHint::GLFW_VISIBLE, TrueFalse::GLFW_FALSE->value);
        glfwWindowHint(WindowHint::GLFW_CONTEXT_VERSION_MAJOR, 2);
        glfwWindowHint(WindowHint::GLFW_CONTEXT_VERSION_MINOR, 1);

        $window = glfwCreateWindow($width, $height, 'ScrapyardIO glfw-ogl headless');

        if (is_null($window)) {
            $error = glfwGetError();

            throw new GlfwGfxException(
                'glfwCreateWindow failed for headless glfw-ogl: '.($error['description'] ?? '')
            );
        }

        return $window;
    }

    protected function glFillRect(int $x, int $y, int $w, int $h, int $color): void
    {
        $this->requireWindow();
        $this->syncDrawableSize();

        $px = (int) round($x * $this->content_scale_x);
        $py = (int) round($y * $this->content_scale_y);
        $pw = max(1, (int) round($w * $this->content_scale_x));
        $ph = max(1, (int) round($h * $this->content_scale_y));
        $gl_y = $this->framebuffer_height - $py - $ph;

        $this->applyClearColor($color);
        glEnable(EnableCap::GL_SCISSOR_TEST);
        glScissor($px, $gl_y, $pw, $ph);
        glClear(ClearBufferMask::GL_COLOR_BUFFER_BIT);
        glDisable(EnableCap::GL_SCISSOR_TEST);
    }

    protected function applyClearColor(int $color): void
    {
        [$r, $g, $b, $a] = $this->unpackRgba($color);
        glClearColor($r / 255.0, $g / 255.0, $b / 255.0, $a / 255.0);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    protected function unpackRgba(int $color): array
    {
        $rg = $this->splitBytes($color >> 16);
        $ba = $this->splitBytes($color);

        return [$rg['high'], $rg['low'], $ba['high'], $ba['low']];
    }

    protected function requireWindow(): GlfwWindow
    {
        if (is_null($this->native_window)) {
            throw new GlfwGfxException('GLFWOpenGLFramebuffer has no native window.');
        }

        return $this->native_window;
    }
}
