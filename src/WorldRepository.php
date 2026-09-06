<?php

declare(strict_types=1);

namespace Voxel;

use RuntimeException;

/**
 * Loads and saves worlds as JSON files. This is the only class that knows
 * anything about how a world is persisted.
 */
final class WorldRepository
{
    public function __construct(private readonly string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function save(World $world): void
    {
        $this->ensureDirectoryExists();

        $json = json_encode($world->toArray(), JSON_THROW_ON_ERROR);

        if (file_put_contents($this->path, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write world file: {$this->path}");
        }
    }

    public function load(): World
    {
        $json = file_get_contents($this->path);

        if ($json === false) {
            throw new RuntimeException("Unable to read world file: {$this->path}");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->hydrate($data);
    }

    /**
     * Rebuilds a World instance from its plain-array representation.
     *
     * @param array<string, mixed> $data
     */
    private function hydrate(array $data): World
    {
        $version = $data['version'] ?? null;

        if ($version !== World::FORMAT_VERSION) {
            throw new RuntimeException(
                sprintf(
                    'Unsupported world format version: %s (expected %d)',
                    var_export($version, true),
                    World::FORMAT_VERSION,
                ),
            );
        }

        $width = (int) ($data['width'] ?? 0);
        $height = (int) ($data['height'] ?? 0);
        $grid = $data['grid'] ?? null;

        if (!is_array($grid)) {
            throw new RuntimeException('World file has no usable grid.');
        }

        $world = new World($width, $height);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $world->setBlock($x, $y, BlockType::from((int) $grid[$y][$x]));
            }
        }

        return $world;
    }

    private function ensureDirectoryExists(): void
    {
        $directory = dirname($this->path);

        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }
    }
}
