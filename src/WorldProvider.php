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

    /** @var int[]|null the surface line of the world last loaded or created */
    private ?array $surfaceLine = null;

    public function loadOrCreate(): World
    {
        if ($this->repository->exists()) {
            $world = $this->repository->load();
            $this->surfaceLine = $this->repository->loadSurfaceLine($world);

            return $world;
        }

        $world = new World($this->width, $this->height);
        $this->surfaceLine = $this->generator->generate($world);

        // A brand new world has no chunks on disk yet, so all of them go out.
        $this->repository->saveAll($world, $this->surfaceLine);

        return $world;
    }

    /**
     * Only meaningful after loadOrCreate().
     *
     * @return int[]
     */
    public function getSurfaceLine(): array
    {
        return $this->surfaceLine ?? [];
    }
}
