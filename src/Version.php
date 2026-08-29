<?php

declare(strict_types=1);

namespace Voxel;

final class Version
{
    public const CURRENT = '0.1.0';

    public function describe(): string
    {
        return 'Voxel ' . self::CURRENT . ' running on PHP ' . PHP_VERSION;
    }
}