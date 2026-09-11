<?php

declare(strict_types=1);

namespace Voxel;

/**
 * In survival mode blocks have to be collected before they can be placed.
 * In creative mode the inventory is ignored entirely.
 */
enum GameMode: string
{
    case Survival = 'survival';
    case Creative = 'creative';

    public function isCreative(): bool
    {
        return $this === self::Creative;
    }

    public function toggled(): self
    {
        return $this === self::Survival ? self::Creative : self::Survival;
    }
}
