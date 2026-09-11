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

$target = $world->getBlock($x, $y);

if (!$target->isBreakable()) {
    respond(409, ['error' => 'Itt nincs mit kibontani.']);
}

$changes = [];

// A door is one object spread over two cells: breaking either half removes
// both, otherwise a stray half would be left floating.
if ($target->isDoor()) {
    $otherY = $target->isDoorBottom() ? $y - 1 : $y + 1;

    if ($world->isInBounds($x, $otherY) && $world->getBlock($x, $otherY)->isDoor()) {
        $world->setBlock($x, $otherY, BlockType::Air);
        $changes[] = ['x' => $x, 'y' => $otherY, 'type' => BlockType::Air->value];
    }
}

$world->setBlock($x, $y, BlockType::Air);
$changes[] = ['x' => $x, 'y' => $y, 'type' => BlockType::Air->value];

// Creative players are not collecting anything, so nothing drops.
if (!$player->getMode()->isCreative()) {
    $drop = $target->drop();

    if ($drop !== null) {
        $player->getInventory()->add($drop);
    }
}

$changes = array_merge($changes, $context['fallingBlocks']->settleColumn($world, $x));

$context['worldRepository']->saveChanges($world, $changes);
$context['playerRepository']->save($player);

respond(200, [
    'changes' => $changes,
    'inventory' => $player->getInventory()->toArray(),
    'mode' => $player->getMode()->value,
]);
