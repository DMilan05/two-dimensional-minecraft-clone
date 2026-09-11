<?php

declare(strict_types=1);

namespace Voxel;

/**
 * The player's position, inventory and game mode. Movement itself is simulated
 * in the browser; this class stores where the player was last seen and answers
 * the questions the server needs for validation.
 */
final class Player
{
    // Bumped when the inventory and game mode were added to the save file.
    public const FORMAT_VERSION = 2;

    public function __construct(
        private float $x,
        private float $y,
        private readonly Inventory $inventory,
        private GameMode $mode,
    ) {
    }

    public function getX(): float
    {
        return $this->x;
    }

    public function getY(): float
    {
        return $this->y;
    }

    public function getInventory(): Inventory
    {
        return $this->inventory;
    }

    public function getMode(): GameMode
    {
        return $this->mode;
    }

    public function moveTo(float $x, float $y): void
    {
        $this->x = $x;
        $this->y = $y;
    }

    public function toggleMode(): void
    {
        $this->mode = $this->mode->toggled();
    }

    /**
     * Distance from the player's centre to the centre of the given block,
     * compared against the reach limit.
     */
    public function canReach(int $blockX, int $blockY): bool
    {
        $centreX = $this->x + GameRules::PLAYER_WIDTH / 2;
        $centreY = $this->y + GameRules::PLAYER_HEIGHT / 2;

        $dx = ($blockX + 0.5) - $centreX;
        $dy = ($blockY + 0.5) - $centreY;

        return sqrt($dx * $dx + $dy * $dy) <= GameRules::REACH;
    }

    /**
     * True when the player's body overlaps the given block, so nothing can be
     * placed there without trapping the player inside it.
     */
    public function occupies(int $blockX, int $blockY): bool
    {
        return $this->x < $blockX + 1
            && $this->x + GameRules::PLAYER_WIDTH > $blockX
            && $this->y < $blockY + 1
            && $this->y + GameRules::PLAYER_HEIGHT > $blockY;
    }

    /**
     * Pushes the player up so it stands on top of the given block row. Used
     * when a block is placed under the player's feet: rather than refusing,
     * the player steps up onto it, the way Minecraft does.
     */
    public function standOn(int $blockY): void
    {
        $this->y = $blockY - GameRules::PLAYER_HEIGHT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'x' => $this->x,
            'y' => $this->y,
            'mode' => $this->mode->value,
            'inventory' => $this->inventory->toArray(),
        ];
    }
}
