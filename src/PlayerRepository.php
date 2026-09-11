<?php

declare(strict_types=1);

namespace Voxel;

use RuntimeException;

/**
 * Stores the player separately from the world: the world is large and rarely
 * changes as a whole, the player is tiny and moves constantly.
 */
final class PlayerRepository
{
    public function __construct(private readonly string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function save(Player $player): void
    {
        $this->ensureDirectoryExists();

        $json = json_encode($player->toArray(), JSON_THROW_ON_ERROR);

        if (file_put_contents($this->path, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write player file: {$this->path}");
        }
    }

    public function load(): Player
    {
        $json = file_get_contents($this->path);

        if ($json === false) {
            throw new RuntimeException("Unable to read player file: {$this->path}");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (($data['version'] ?? null) !== Player::FORMAT_VERSION) {
            throw new RuntimeException('Unsupported player format version.');
        }

        return new Player(
            (float) $data['x'],
            (float) $data['y'],
            new Inventory($data['inventory'] ?? []),
            GameMode::from($data['mode'] ?? GameMode::Survival->value),
        );
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
