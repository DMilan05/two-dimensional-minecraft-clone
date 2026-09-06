<?php

declare(strict_types=1);

namespace Voxel;

/**
 * Fills a world with a simple layered terrain: air above, a single grass row
 * on the surface, dirt below it, and stone all the way down.
 *
 * The surface height comes from two sine waves added together: a long one for
 * the overall shape of the hills, and a shorter one for smaller bumps. Adding
 * waves of different lengths is the cheapest way to make a curve look less
 * mechanical.
 */
final class WorldGenerator
{
    private const SURFACE_RATIO = 0.45;
    private const DIRT_DEPTH = 4;

    private const HILL_AMPLITUDE = 6.0;
    private const HILL_WAVELENGTH = 18.0;

    private const DETAIL_AMPLITUDE = 2.0;
    private const DETAIL_WAVELENGTH = 5.0;

    public function generate(World $world): void
    {
        $width = $world->getWidth();
        $height = $world->getHeight();

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

    /**
     * The offset is subtracted, so a positive sine value raises the ground.
     * Screen coordinates grow downwards, which would otherwise turn every hill
     * into a valley.
     */
    private function surfaceHeightAt(int $x, int $baseSurfaceY): int
    {
        $offset = self::HILL_AMPLITUDE * sin($x / self::HILL_WAVELENGTH)
            + self::DETAIL_AMPLITUDE * sin($x / self::DETAIL_WAVELENGTH);

        return $baseSurfaceY - (int) round($offset);
    }
}
