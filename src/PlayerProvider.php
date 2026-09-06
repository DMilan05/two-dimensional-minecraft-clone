<?php

declare(strict_types=1);

namespace Voxel;

/**
 * Returns the stored player, or spawns a new one standing on the surface in
 * the middle of the world.
 */
final class PlayerProvider
{
    public function __construct(private readonly PlayerRepository $repository)
    {
    }

    public function loadOrCreate(World $world): Player
    {
        if ($this->repository->exists()) {
            return $this->repository->load();
        }

        $player = $this->spawn($world);
        $this->repository->save($player);

        return $player;
    }

    private function spawn(World $world): Player
    {
        $spawnX = intdiv($world->getWidth(), 2);
        $surfaceY = $this->findSurface($world, $spawnX);

        // Stand on top of the surface block, centred horizontally on the column.
        $x = $spawnX + (1 - GameRules::PLAYER_WIDTH) / 2;
        $y = $surfaceY - GameRules::PLAYER_HEIGHT;

        return new Player($x, $y);
    }

    /**
     * Returns the row of the topmost solid block in the given column, or the
     * bottom of the world when the column is empty.
     */
    private function findSurface(World $world, int $x): int
    {
        for ($y = 0; $y < $world->getHeight(); $y++) {
            if ($world->getBlock($x, $y)->isSolid()) {
                return $y;
            }
        }

        return $world->getHeight();
    }
}
