<?php
declare(strict_types=1);
namespace Voxel;
enum BlockType: int
{
    case Air = 0;
    case Dirt = 1;
    case Stone = 2;
    case Grass = 3;

    public function isSolid(): bool
    {
        return match ($this) {
            BlockType::Air => false,
            default => true,
        };
    }

    public function isBreakable(): bool
    {
        return match ($this) {
            BlockType::Air => false,
            default => true,
        };
    }
}

