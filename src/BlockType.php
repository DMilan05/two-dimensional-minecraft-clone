<?php

declare(strict_types=1);

namespace Voxel;

/**
 * A door occupies two cells and has an open and a closed state. Since the grid
 * stores nothing but a type number per cell, that state lives in the type
 * itself - hence four door cases instead of one.
 */
enum BlockType: int
{
    case Air = 0;
    case Dirt = 1;
    case Stone = 2;
    case Grass = 3;
    case Sand = 4;
    case Wood = 5;
    case Leaves = 6;
    case Bedrock = 7;
    case DoorClosedBottom = 8;
    case DoorClosedTop = 9;
    case DoorOpenBottom = 10;
    case DoorOpenTop = 11;

    /**
     * Whether the block stops movement. An open door does not.
     */
    public function isSolid(): bool
    {
        return match ($this) {
            self::Air, self::DoorOpenBottom, self::DoorOpenTop => false,
            default => true,
        };
    }

    /**
     * Whether the player can break the block. Bedrock is the first block that
     * is solid but indestructible, which is why these two questions are not
     * the same question.
     */
    public function isBreakable(): bool
    {
        return match ($this) {
            self::Air, self::Bedrock => false,
            default => true,
        };
    }

    /**
     * Whether the player can place the block. Bedrock is world structure, and
     * the upper half of a door is placed by the lower half, never on its own.
     */
    public function isPlaceable(): bool
    {
        return match ($this) {
            self::Air, self::Bedrock, self::DoorClosedTop, self::DoorOpenTop, self::DoorOpenBottom => false,
            default => true,
        };
    }

    /**
     * Whether the block falls when there is nothing underneath it.
     */
    public function hasGravity(): bool
    {
        return $this === self::Sand;
    }

    public function isDoor(): bool
    {
        return match ($this) {
            self::DoorClosedBottom, self::DoorClosedTop, self::DoorOpenBottom, self::DoorOpenTop => true,
            default => false,
        };
    }

    public function isDoorBottom(): bool
    {
        return $this === self::DoorClosedBottom || $this === self::DoorOpenBottom;
    }

    /**
     * The same half of the door in the opposite state.
     */
    public function toggledDoor(): self
    {
        return match ($this) {
            self::DoorClosedBottom => self::DoorOpenBottom,
            self::DoorClosedTop => self::DoorOpenTop,
            self::DoorOpenBottom => self::DoorClosedBottom,
            self::DoorOpenTop => self::DoorClosedTop,
            default => $this,
        };
    }

    /**
     * The upper half matching this lower half.
     */
    public function upperHalf(): self
    {
        return match ($this) {
            self::DoorClosedBottom => self::DoorClosedTop,
            self::DoorOpenBottom => self::DoorOpenTop,
            default => $this,
        };
    }
}
