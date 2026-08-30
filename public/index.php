<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Voxel\BlockType;
use Voxel\World;

header('Content-Type: text/plain; charset=utf-8');

$world = new World(8, 4);

// 1. Writing blocks
$world->setBlock(0, 0, BlockType::Grass);
$world->setBlock(3, 2, BlockType::Stone);
$world->setBlock(7, 3, BlockType::Dirt);

// 2. Reading them back
echo "getBlock(0,0): " . $world->getBlock(0, 0)->name . PHP_EOL;
echo "getBlock(3,2): " . $world->getBlock(3, 2)->name . PHP_EOL;
echo "getBlock(1,1): " . $world->getBlock(1, 1)->name . PHP_EOL;

// 3. Out of bounds read - should fall back to Air
echo "getBlock(99,99): " . $world->getBlock(99, 99)->name . PHP_EOL;

// 4. Out of bounds write - should throw
try {
    $world->setBlock(99, 99, BlockType::Stone);
    echo "ERROR: no exception was thrown" . PHP_EOL;
} catch (InvalidArgumentException $e) {
    echo "Caught: " . $e->getMessage() . PHP_EOL;
}

// 5. Full grid dump
echo PHP_EOL;
for ($y = 0; $y < 4; $y++) {
    for ($x = 0; $x < 8; $x++) {
        echo $world->getBlock($x, $y)->value;
    }
    echo PHP_EOL;
}