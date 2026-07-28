<?php

namespace Microscrap\GFX\GLFW\Console;

use Fabricate\Console\Command;
use Fabricate\Filesystem\Filesystem;
use Fabricate\NutsAndBolts\Composer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

use function Fabricate\NutsAndBolts\Helpers\php_binary;
use function Fabricate\NutsAndBolts\Helpers\workshop_binary;

#[AsCommand(name: 'install:glfw-display')]
class InstallGlfwDisplayCommand extends Command
{
    protected ?string $signature = 'install:glfw-display
                    {--composer=global : Absolute path to the Composer binary which should be used to install packages}
                    {--force : Overwrite existing windowed.glfw configuration after install}';

    protected string $description = 'Install the ScrapyardIO GLFW display package';

    public function isHidden(): bool
    {
        return $this->displayPackageInstalled();
    }

    public function handle(): int
    {
        $package = $this->displayPackageName();

        if ($this->displayPackageInstalled()) {
            $this->components->info("[{$package}] is already installed.");

            return self::SUCCESS;
        }

        $composerBinary = $this->option('composer') === 'global' ? null : (string) $this->option('composer');

        $installed = $this->makeComposer()->requirePackages(
            ["{$package}:^0.6.0"],
            false,
            $this->output,
            $composerBinary,
        );

        if (! $installed) {
            $this->components->error("Unable to install [{$package}].");

            return self::FAILURE;
        }

        $configure = [
            'config:glfw-display',
        ];

        if ($this->option('force')) {
            $configure[] = '--force';
        }

        $status = $this->runWorkshopCommand($configure);

        if ($status !== self::SUCCESS) {
            $this->components->warn('GLFW display package installed, but windowed config was not applied automatically. Run [workshop config:glfw-display].');

            return self::SUCCESS;
        }

        $this->components->info('GLFW display package installed successfully.');

        return self::SUCCESS;
    }

    protected function displayPackageName(): string
    {
        return 'dept-of-scrapyard-robotics/glfw-display';
    }

    protected function displayPackageInstalled(): bool
    {
        $package = $this->displayPackageName();

        try {
            return $this->makeComposer()->hasPackage($package);
        } catch (\RuntimeException) {
            return class_exists(\Composer\InstalledVersions::class)
                && \Composer\InstalledVersions::isInstalled($package);
        }
    }

    protected function makeComposer(): Composer
    {
        $basePath = isset($this->scrapyard_io)
            ? $this->scrapyard_io->basePath()
            : base_path();

        return new Composer(new Filesystem, $basePath);
    }

    /**
     * @param  array<int, string>  $arguments
     */
    protected function runWorkshopCommand(array $arguments): int
    {
        $workshop = file_exists($local = $this->scrapyard_io->basePath('workshop'))
            ? $local
            : workshop_binary();

        $process = new Process(
            [php_binary(), $workshop, ...$arguments],
            $this->scrapyard_io->basePath(),
            ['COMPOSER_MEMORY_LIMIT' => '-1'],
        );

        $process->setTimeout(null);

        return $process->run(function (string $type, string $output): void {
            $this->output->write($output);
        });
    }
}
