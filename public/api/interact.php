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
    respond(409, ['error' => 'Ez túl messze van.']);
}

$target = $world->getBlock($x, $y);

if (!$target->isDoor()) {
    respond(409, ['error' => 'Ezt nem lehet használni.']);
}

// Work out where the two halves are, whichever one was clicked.
$bottomY = $target->isDoorBottom() ? $y : $y + 1;
$topY = $bottomY - 1;

$bottom = $world->getBlock($x, $bottomY);
$top = $world->getBlock($x, $topY);

if (!$bottom->isDoor() || !$top->isDoor()) {
    respond(409, ['error' => 'Ez az ajtó sérült.']);
}

$newBottom = $bottom->toggledDoor();
$newTop = $top->toggledDoor();

// Closing a door on top of the player would trap them inside a solid block.
if ($newBottom->isSolid() && ($player->occupies($x, $bottomY) || $player->occupies($x, $topY))) {
    respond(409, ['error' => 'Nem tudod becsukni, mert az ajtóban állsz.']);
}

$world->setBlock($x, $bottomY, $newBottom);
$world->setBlock($x, $topY, $newTop);

$changes = [
    ['x' => $x, 'y' => $bottomY, 'type' => $newBottom->value],
    ['x' => $x, 'y' => $topY, 'type' => $newTop->value],
];

$changes = array_merge($changes, $context['fallingBlocks']->settleColumn($world, $x));

$context['worldRepository']->saveChanges($world, $changes);

respond(200, [
    'changes' => $changes,
    'inventory' => $player->getInventory()->toArray(),
    'mode' => $player->getMode()->value,
]);
