<?php

declare(strict_types=1);

namespace Voxel;

/**
 * Applies gravity to a single column after it has been modified. Only the
 * touched column can be affected, because falling is strictly vertical.
 *
 * Scanning from the bottom upwards settles a whole stack in one pass: by the
 * time a block is considered, everything below it has already come to rest.
 */
final class FallingBlocks
{
    /**
     * @return list<array{x: int, y: int, type: int}> the cells that changed
     */
    public function settleColumn(World $world, int $x): array
    {
        $changes = [];

        for ($y = $world->getHeight() - 2; $y >= 0; $y--) {
            $type = $world->getBlock($x, $y);

            if (!$type->hasGravity()) {
                continue;
            }

            $restY = $y;

            while (
                $restY + 1 < $world->getHeight()
                && $world->getBlock($x, $restY + 1) === BlockType::Air
            ) {
                $restY++;
            }

            if ($restY === $y) {
                continue;
            }

            $world->setBlock($x, $y, BlockType::Air);
            $world->setBlock($x, $restY, $type);

            $changes[] = ['x' => $x, 'y' => $y, 'type' => BlockType::Air->value];
            $changes[] = ['x' => $x, 'y' => $restY, 'type' => $type->value];
        }

        return $changes;
    }
}
