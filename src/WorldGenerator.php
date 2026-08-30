<?php

declare(strict_types=1);

namespace Voxel;

final class WorldGenerator
{
    private const SURFACE_RATIO = 0.6;
    private const DIRT_DEPTH = 4;
    private const HILL_AMPLITUDE = 3;
    private const HILL_WAVELENGTH = 4.0;

    public function generate(World $world): void
    {
        $height = $world->getHeight();
        $width = $world->getWidth();

        $baseSurfaceY = (int) ($height * self::SURFACE_RATIO);

        for ($x = 0; $x < $width; $x++) {
            $surfaceY = $this->surfaceHeightAt($x, $baseSurfaceY);
            $stoneY = $surfaceY + self::DIRT_DEPTH;

            for ($y = 0; $y < $height; $y++) {
                $type = match (true) {
                    $y < $surfaceY => BlockType::Air,
                    $y === $surfaceY => BlockType::Grass,
                    $y < $stoneY => BlockType::Dirt,
                    default => BlockType::Stone,
                };

                $world->setBlock($x, $y, $type);
            }
        }
    }

    private function surfaceHeightAt(int $x, int $baseSurfaceY): int
    {
        $offset = self::HILL_AMPLITUDE * sin($x / self::HILL_WAVELENGTH);

        return $baseSurfaceY + (int) round($offset);
    }
}