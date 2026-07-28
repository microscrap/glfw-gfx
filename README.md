# microscrap/glfw-gfx

[![Latest Version on Packagist](https://img.shields.io/packagist/v/microscrap/glfw-gfx.svg)](https://packagist.org/packages/microscrap/glfw-gfx)
[![License](https://img.shields.io/packagist/l/microscrap/glfw-gfx.svg)](LICENSE)

GLFW-native rendering for the ScrapyardIO Framework.

This package registers the `glfw-ogl` framebuffer strategy and the `glfw` GFX render driver so Fabricate can draw through a GLFW OpenGL context.

It is the rendering half of the GLFW desktop stack. For an actual windowed display panel, install [`dept-of-scrapyard-robotics/glfw-display`](https://packagist.org/packages/dept-of-scrapyard-robotics/glfw-display).

## Requirements

* PHP 8.3+
* **ext-glfw** ^0.5.0 — [php-io-extensions/glfw](https://github.com/php-io-extensions/glfw)
* [`microscrap/glfw`](https://packagist.org/packages/microscrap/glfw) ^0.5.0
* ScrapyardIO Framework 0.6 (`fabricate/framebuffers`, `fabricate/rendering`, …)

## Installation

Confirm the extension is loaded:

```bash
php -m | grep glfw
```

### Via Composer

```bash
composer require microscrap/glfw-gfx
```

Package discovery registers `Microscrap\GFX\GLFW\Providers\GLFWGfxServiceProvider` automatically.

### Via Workshop (recommended in a Scrapyard app)

From a ScrapyardIO application:

```bash
workshop install:gfx --glfw
```

Or interactively:

```bash
workshop install:gfx
```

That installs this package (and optional siblings), then can activate GLFW as the default rendering backend.

## What it registers

| Registry key | Role |
|---|---|
| Framebuffer `glfw-ogl` | `GLFWOpenGLFramebuffer` |
| Renderer `glfw` | `GLFWRenderDriver` |

Typical `config/gfx.php` after activation:

```php
return [
    'rendering' => [
        'default' => 'glfw',
        'engines' => [
            'glfw' => [],
            // ...
        ],
    ],
];
```

## Pair with a windowed display

This package alone does not open a desktop window. Once GFX is installed, Workshop exposes:

```bash
workshop install:glfw-display
```

That command is **hidden** when `dept-of-scrapyard-robotics/glfw-display` is already required. When visible, it:

1. `composer require dept-of-scrapyard-robotics/glfw-display:^0.6.0`
2. Runs `workshop config:glfw-display` to add a default `windowed.glfw` entry

Options:

```bash
workshop install:glfw-display
workshop install:glfw-display --force
workshop install:glfw-display --composer=/path/to/composer
```

## Manual wiring (without install:gfx)

```bash
composer require microscrap/glfw-gfx
php workshop package:discover
```

Then set `config/gfx.php` `rendering.default` to `glfw` (or keep another default and select `glfw` / `glfw-ogl` per display).

## Stack overview

```text
ext-glfw
  └── microscrap/glfw          (bindings)
        └── microscrap/glfw-gfx          (framebuffer + renderer)  ← this package
              └── dept-of-scrapyard-robotics/glfw-display  (windowed panel)
```

## License

MIT
