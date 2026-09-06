<?php

declare(strict_types=1);

namespace Voxel;

/**
 * The player's position in the world. Movement itself is simulated in the
 * browser; this class only stores where the player was last seen and answers
 * questions the server needs for validation.
 */
final class Player
{
    public const FORMAT_VERSION = 1;

    public function __construct(
        private float $x,
        private float $y,
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

    public function moveTo(float $x, float $y): void
    {
        $this->x = $x;
        $this->y = $y;
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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'x' => $this->x,
            'y' => $this->y,
        ];
    }
}
