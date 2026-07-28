<?php

namespace Microscrap\GFX\GLFW;

use Fabricate\Rendering\GFXRenderDriver;

class GLFWRenderDriver extends GFXRenderDriver
{
    public function __construct()
    {
        parent::__construct(new GLFWGfx);
    }
}
