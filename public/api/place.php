<?php

declare(strict_types=1);

use Voxel\BlockType;
use Voxel\GameRules;
use Voxel\Player;
use Voxel\World;

$context = require __DIR__ . '/bootstrap.php';

requirePostMethod();

$input = readJsonBody();

[$x, $y] = readCoordinates($input);

$requestedType = $input['type'] ?? null;

if (!is_int($requestedType)) {
    respond(400, ['error' => 'The "type" field must be an integer.']);
}

$type = BlockType::tryFrom($requestedType);

if ($type === null) {
    respond(400, ['error' => "Unknown block type: {$requestedType}"]);
}

if (!$type->isPlaceable()) {
    respond(400, ['error' => 'Ezt a blokkot nem lehet lerakni.']);
}

$world = $context['worldProvider']->loadOrCreate();

if (!$world->isInBounds($x, $y)) {
    respond(400, ['error' => "Coordinates {$x},{$y} are outside the world."]);
}

$player = $context['playerProvider']->loadOrCreate($world);

if (!$player->canReach($x, $y)) {
    respond(409, ['error' => 'Ez a hely túl messze van.']);
}

// A door needs its own cell and the one above it.
$cells = $type->isDoorBottom() ? [[$x, $y], [$x, $y - 1]] : [[$x, $y]];

foreach ($cells as [$cellX, $cellY]) {
    if (!$world->isInBounds($cellX, $cellY)) {
        respond(409, ['error' => 'Nincs elég hely az ajtónak.']);
    }

    if ($world->getBlock($cellX, $cellY) !== BlockType::Air) {
        respond(409, ['error' => 'Itt már van blokk.']);
    }
}

// Placing a block into the player's own space is allowed in one case: a single
// block right under the feet, with room to step up onto it. That is how you
// pillar out of a hole.
$liftPlayer = false;

foreach ($cells as [$cellX, $cellY]) {
    if (!$player->occupies($cellX, $cellY)) {
        continue;
    }

    if (count($cells) > 1 || !canStepUp($world, $player, $cellX, $cellY)) {
        respond(409, ['error' => 'Ide nem rakhatsz, mert te állsz ott.']);
    }

    $liftPlayer = true;
}

if (!hasSupport($world, $x, $y)) {
    respond(409, ['error' => 'Blokkot csak meglévő blokk mellé lehet rakni.']);
}

// In survival the block has to come out of the inventory. The check and the
// deduction are the same call, so there is no way to place without paying.
if (!$player->getMode()->isCreative() && !$player->getInventory()->remove($type)) {
    respond(409, ['error' => 'Nincs több ilyen blokkod.']);
}

$changes = [];

$world->setBlock($x, $y, $type);
$changes[] = ['x' => $x, 'y' => $y, 'type' => $type->value];

if ($type->isDoorBottom()) {
    $upper = $type->upperHalf();
    $world->setBlock($x, $y - 1, $upper);
    $changes[] = ['x' => $x, 'y' => $y - 1, 'type' => $upper->value];
}

if ($liftPlayer) {
    $player->standOn($y);
}

$changes = array_merge($changes, $context['fallingBlocks']->settleColumn($world, $x));

$context['worldRepository']->saveChanges($world, $changes);
$context['playerRepository']->save($player);

respond(200, [
    'changes' => $changes,
    'inventory' => $player->getInventory()->toArray(),
    'mode' => $player->getMode()->value,
    // Sent back only when the player was pushed up, so the browser can move
    // its simulated player to the same place.
    'playerY' => $liftPlayer ? $player->getY() : null,
]);

/**
 * Whether the player can be lifted onto a block placed at the given cell.
 *
 * Two conditions: the block has to be at the player's feet rather than at head
 * height, and the space the player would move into has to be clear.
 */
function canStepUp(World $world, Player $player, int $x, int $y): bool
{
    $feetY = (int) floor($player->getY() + GameRules::PLAYER_HEIGHT - 0.0001);

    if ($y !== $feetY) {
        return false;
    }

    $headroomTop = (int) floor($y - GameRules::PLAYER_HEIGHT);

    for ($checkY = $headroomTop; $checkY < $y; $checkY++) {
        if ($world->getBlock($x, $checkY)->isSolid()) {
            return false;
        }
    }

    return true;
}

/**
 * A new block needs something to attach to. With ALLOW_SIDEWAYS_PLACEMENT any
 * solid neighbour will do; otherwise only the block directly below counts.
 *
 * Blocks outside the world read as Air, so the world edges are not treated as
 * support.
 */
function hasSupport(World $world, int $x, int $y): bool
{
    if ($world->getBlock($x, $y + 1)->isSolid()) {
        return true;
    }

    if (!GameRules::ALLOW_SIDEWAYS_PLACEMENT) {
        return false;
    }

    return $world->getBlock($x, $y - 1)->isSolid()
        || $world->getBlock($x - 1, $y)->isSolid()
        || $world->getBlock($x + 1, $y)->isSolid();
}
