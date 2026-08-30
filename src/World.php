<?php
declare(strict_types=1);

namespace Voxel;

use InvalidArgumentException;

final class World
{
    private int $width;
    private int $height;

    /** @var BlockType[][] */
    private array $grid;

    public function __construct(int $width, int $height)
    {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException("World dimensions must be positive, got {$width}x{$height}");
        }
        $this->width = $width;
        $this->height = $height;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $this->grid[$y][$x] = BlockType::Air;
            }
        }
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function isInBounds(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < $this->width && $y < $this->height;
    }

    public function getBlock(int $x, int $y): BlockType
    {
        if (!$this->isInBounds($x, $y)) {
            return BlockType::Air;
        }

        return $this->grid[$y][$x];
    }

    public function setBlock(int $x, int $y, BlockType $type): void
    {
        if (!$this->isInBounds($x, $y)) {
            throw new InvalidArgumentException("Selected block {$x},{$y} is out of bounds. The world is: {$this->width}x{$this->height}");
        }

        $this->grid[$y][$x] = $type;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'width' => $this->width,
            'height' => $this->height,
            'grid' => $this->grid
        ];
    }
}
