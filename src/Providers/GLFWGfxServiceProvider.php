<?php

namespace Microscrap\GFX\GLFW\Providers;

use Fabricate\Contracts\Chassis\BindingResolutionException;
use Fabricate\Contracts\Framebuffers\Factory as FramebufferFactory;
use Fabricate\Contracts\Framebuffers\FormatSpec;
use Fabricate\NutsAndBolts\ServiceProvider;
use Microscrap\GFX\GLFW\GLFWOpenGLFramebuffer;

/**
 * Package discovery entry for microscrap/glfw-gfx.
 *
 * Registers the glfw-ogl framebuffer strategy (OpenGL backbuffer + CPU shadow),
 * mirroring sdl3-gfx's 'sdl3' extend.
 */
class GLFWGfxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->callAfterResolving('framebuffer', function (FramebufferFactory $framebuffers) {
            $framebuffers->extend(
                'glfw-ogl',
                fn (int $width, int $height, FormatSpec $formatSpec) => new GLFWOpenGLFramebuffer(
                    $formatSpec,
                    $width,
                    $height,
                )
            );
        });
    }
}
