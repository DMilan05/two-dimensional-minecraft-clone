<?php

declare(strict_types=1);

namespace Voxel;

/**
 * Returns the world every request should work with: the saved one if there is
 * any, otherwise a freshly generated (and immediately persisted) one.
 */
final class WorldProvider
{
    public function __construct(
        private readonly WorldRepository $repository,
        private readonly WorldGenerator $generator,
        private readonly int $width,
        private readonly int $height,
    ) {
    }

    public function loadOrCreate(): World
    {
        if ($this->repository->exists()) {
            return $this->repository->load();
        }

        $world = new World($this->width, $this->height);
        $this->generator->generate($world);
        $this->repository->save($world);

        return $world;
    }
}
