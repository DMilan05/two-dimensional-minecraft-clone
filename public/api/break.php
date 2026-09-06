<?php

declare(strict_types=1);

use Voxel\BlockType;

$context = require __DIR__ . '/bootstrap.php';

requirePostMethod();

[$x, $y] = readCoordinates(readJsonBody());

$world = $context['worldProvider']->loadOrCreate();

if (!$world->isInBounds($x, $y)) {
    respond(400, ['error' => "Coordinates {$x},{$y} are outside the world."]);
}

$player = $context['playerProvider']->loadOrCreate($world);

if (!$player->canReach($x, $y)) {
    respond(409, ['error' => 'Ez a blokk túl messze van.']);
}

if (!$world->getBlock($x, $y)->isBreakable()) {
    respond(409, ['error' => 'Itt nincs mit kibontani.']);
}

$world->setBlock($x, $y, BlockType::Air);
$context['worldRepository']->save($world);

respond(200, [
    'x' => $x,
    'y' => $y,
    'type' => BlockType::Air->value,
]);
