<?php

namespace Microscrap\GFX\GLFW\Providers;

use Fabricate\Contracts\Chassis\BindingResolutionException;
use Fabricate\Contracts\Framebuffers\BufferFactory as FramebufferFactory;
use Fabricate\Framebuffers\FormatSpec;
use Fabricate\NutsAndBolts\ServiceProvider;
use Fabricate\Rendering\RenderManager;
use Microscrap\GFX\GLFW\Console\InstallGlfwDisplayCommand;
use Microscrap\GFX\GLFW\GLFWRenderDriver;
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
        $this->program->singleton(InstallGlfwDisplayCommand::class);

        $this->commands([
            InstallGlfwDisplayCommand::class,
        ]);
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

        $this->callAfterResolving('gfx', function (RenderManager $renderers) {
            $renderers->extend('glfw', fn () => new GLFWRenderDriver);
        });
    }
}
