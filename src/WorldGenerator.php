<?php

declare(strict_types=1);

namespace Voxel;

/**
 * Builds the terrain in four passes, each with a single job:
 *
 *   1. fillTerrain  - air, surface, dirt and stone layers
 *   2. carveCaves   - random walks that hollow out the stone
 *   3. layBedrock   - an indestructible floor
 *   4. plantTrees   - trunks and canopies on grass
 *
 * Splitting them up keeps each pass short, and makes it easy to disable one
 * while working on another.
 */
final class WorldGenerator
{
    private const SURFACE_RATIO = 0.45;
    private const DIRT_DEPTH = 4;

    private const HILL_AMPLITUDE = 6.0;
    private const HILL_WAVELENGTH = 18.0;

    private const DETAIL_AMPLITUDE = 2.0;
    private const DETAIL_WAVELENGTH = 5.0;

    /** Columns sunk at least this far below the base line become sandy. */
    private const SAND_DEPTH_THRESHOLD = 3;

    private const BEDROCK_DEPTH = 2;

    /** Caves per 100 columns of world. */
    private const CAVES_PER_100_COLUMNS = 5;
    private const CAVE_STEPS = 140;
    private const CAVE_MIN_DEPTH_BELOW_SURFACE = 6;

    private const TREE_CHANCE_PERCENT = 14;
    private const TREE_MIN_GAP = 4;
    private const TRUNK_MIN_HEIGHT = 4;
    private const TRUNK_MAX_HEIGHT = 6;
    private const CANOPY_RADIUS = 2;

    public function generate(World $world): void
    {
        $baseSurfaceY = (int) ($world->getHeight() * self::SURFACE_RATIO);
        $surface = $this->buildSurfaceMap($world, $baseSurfaceY);

        $this->fillTerrain($world, $surface, $baseSurfaceY);
        $this->carveCaves($world, $surface);
        $this->layBedrock($world);
        $this->plantTrees($world, $surface);
    }

    /**
     * Surface row for every column, from two sine waves added together: a long
     * one for the hills, a short one for smaller bumps.
     *
     * @return int[]
     */
    private function buildSurfaceMap(World $world, int $baseSurfaceY): array
    {
        $surface = [];

        for ($x = 0; $x < $world->getWidth(); $x++) {
            $offset = self::HILL_AMPLITUDE * sin($x / self::HILL_WAVELENGTH)
                + self::DETAIL_AMPLITUDE * sin($x / self::DETAIL_WAVELENGTH);

            // Subtracted, because screen coordinates grow downwards and a
            // positive sine value should raise the ground.
            $surface[$x] = $baseSurfaceY - (int) round($offset);
        }

        return $surface;
    }

    /**
     * @param int[] $surface
     */
    private function fillTerrain(World $world, array $surface, int $baseSurfaceY): void
    {
        $height = $world->getHeight();

        for ($x = 0; $x < $world->getWidth(); $x++) {
            $surfaceY = $surface[$x];
            $stoneY = $surfaceY + self::DIRT_DEPTH;
            $sandy = $surfaceY >= $baseSurfaceY + self::SAND_DEPTH_THRESHOLD;

            $topType = $sandy ? BlockType::Sand : BlockType::Grass;
            $fillType = $sandy ? BlockType::Sand : BlockType::Dirt;

            for ($y = 0; $y < $height; $y++) {
                $type = match (true) {
                    $y < $surfaceY => BlockType::Air,
                    $y === $surfaceY => $topType,
                    $y < $stoneY => $fillType,
                    default => BlockType::Stone,
                };

                $world->setBlock($x, $y, $type);
            }
        }
    }

    /**
     * Hollows out tunnels with random walks. Each step clears a small disc
     * around the current position, then wanders one block in a random
     * direction. Cheap, and the result looks organic enough.
     *
     * @param int[] $surface
     */
    private function carveCaves(World $world, array $surface): void
    {
        $width = $world->getWidth();
        $height = $world->getHeight();

        $caveCount = max(1, intdiv($width * self::CAVES_PER_100_COLUMNS, 100));

        for ($cave = 0; $cave < $caveCount; $cave++) {
            $x = random_int(2, $width - 3);

            $minY = $surface[$x] + self::CAVE_MIN_DEPTH_BELOW_SURFACE;
            $maxY = $height - self::BEDROCK_DEPTH - 3;

            if ($minY >= $maxY) {
                continue;
            }

            $y = random_int($minY, $maxY);

            for ($step = 0; $step < self::CAVE_STEPS; $step++) {
                $this->carveDisc($world, $x, $y, $surface);

                $x = max(1, min($width - 2, $x + random_int(-1, 1)));
                $y = max($minY, min($maxY, $y + random_int(-1, 1)));
            }
        }
    }

    /**
     * @param int[] $surface
     */
    private function carveDisc(World $world, int $centreX, int $centreY, array $surface): void
    {
        for ($y = $centreY - 1; $y <= $centreY + 1; $y++) {
            for ($x = $centreX - 1; $x <= $centreX + 1; $x++) {
                if (!$world->isInBounds($x, $y)) {
                    continue;
                }

                // Never break through to the sky, and never touch bedrock.
                if ($y <= $surface[$x]) {
                    continue;
                }

                if (!$world->getBlock($x, $y)->isBreakable()) {
                    continue;
                }

                $world->setBlock($x, $y, BlockType::Air);
            }
        }
    }

    private function layBedrock(World $world): void
    {
        $height = $world->getHeight();

        for ($y = $height - self::BEDROCK_DEPTH; $y < $height; $y++) {
            for ($x = 0; $x < $world->getWidth(); $x++) {
                $world->setBlock($x, $y, BlockType::Bedrock);
            }
        }
    }

    /**
     * @param int[] $surface
     */
    private function plantTrees(World $world, array $surface): void
    {
        $lastTreeX = -self::TREE_MIN_GAP;

        for ($x = self::CANOPY_RADIUS; $x < $world->getWidth() - self::CANOPY_RADIUS; $x++) {
            if ($x - $lastTreeX < self::TREE_MIN_GAP) {
                continue;
            }

            $groundY = $surface[$x];

            if ($world->getBlock($x, $groundY) !== BlockType::Grass) {
                continue;
            }

            if (random_int(1, 100) > self::TREE_CHANCE_PERCENT) {
                continue;
            }

            if ($this->plantTree($world, $x, $groundY)) {
                $lastTreeX = $x;
            }
        }
    }

    /**
     * Returns false when there is not enough room above the ground.
     */
    private function plantTree(World $world, int $x, int $groundY): bool
    {
        $trunkHeight = random_int(self::TRUNK_MIN_HEIGHT, self::TRUNK_MAX_HEIGHT);
        $topY = $groundY - $trunkHeight;

        if ($topY - self::CANOPY_RADIUS < 0) {
            return false;
        }

        for ($y = $groundY - 1; $y >= $topY; $y--) {
            $world->setBlock($x, $y, BlockType::Wood);
        }

        // The canopy narrows towards the top and only fills empty space, so it
        // never eats into the trunk or a neighbouring tree.
        for ($y = $topY - self::CANOPY_RADIUS; $y <= $topY; $y++) {
            $spread = $y === $topY - self::CANOPY_RADIUS ? 1 : self::CANOPY_RADIUS;

            for ($leafX = $x - $spread; $leafX <= $x + $spread; $leafX++) {
                if (!$world->isInBounds($leafX, $y)) {
                    continue;
                }

                if ($world->getBlock($leafX, $y) !== BlockType::Air) {
                    continue;
                }

                $world->setBlock($leafX, $y, BlockType::Leaves);
            }
        }

        return true;
    }
}
