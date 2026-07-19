<?php

namespace Microscrap\GFX\GLFW;

use Fabricate\Contracts\Gfx\RendererException;

class GlfwGfxException extends RendererException
{
    public static function extensionMissing(): static
    {
        return new static('The GLFW rendering engine requires the glfw extension for PHP. Install it with pie install php-io-extensions/glfw');
    }

    public static function packageMissing(): static
    {
        return new static('The GLFW rendering engine requires microscrap/glfw. Install it with composer require microscrap/glfw');
    }
}
