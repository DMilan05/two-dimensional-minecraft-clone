<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Voxel\World;
use Voxel\WorldGenerator;

header('Content-Type: application/json; charset=utf-8');

$world = new World(64, 32);
(new WorldGenerator())->generate($world);

echo json_encode($world->toArray());