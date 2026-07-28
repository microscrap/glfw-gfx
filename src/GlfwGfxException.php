<?php

namespace Microscrap\GFX\GLFW;

use Fabricate\Contracts\Rendering\RenderingException;

class GlfwGfxException extends RenderingException
{
    public static function extensionMissing(): static
    {
        return new static('The GLFW rendering engine requires the glfw extension for PHP. Install it with pie install php-io-extensions/glfw');
    }

    public static function packageMissing(): static
    {
        return new static('The GLFW rendering engine requires microscrap/glfw. Install it with composer require microscrap/glfw');
    }

    public static function unsupportedFramebuffer(string $class): static
    {
        return new static("GLFW rendering requires a ".GLFWOpenGLFramebuffer::class."; {$class} given.");
    }
}
