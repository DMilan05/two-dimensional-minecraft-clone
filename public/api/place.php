<?php

declare(strict_types=1);

use Voxel\BlockType;
use Voxel\GameRules;
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

if ($type === BlockType::Air) {
    respond(400, ['error' => 'Use the break endpoint to remove a block.']);
}

$world = $context['worldProvider']->loadOrCreate();

if (!$world->isInBounds($x, $y)) {
    respond(400, ['error' => "Coordinates {$x},{$y} are outside the world."]);
}

$player = $context['playerProvider']->loadOrCreate($world);

if (!$player->canReach($x, $y)) {
    respond(409, ['error' => 'Ez a hely túl messze van.']);
}

if ($world->getBlock($x, $y) !== BlockType::Air) {
    respond(409, ['error' => 'Itt már van blokk.']);
}

if ($player->occupies($x, $y)) {
    respond(409, ['error' => 'Ide nem rakhatsz, mert te állsz ott.']);
}

if (!hasSupport($world, $x, $y)) {
    respond(409, ['error' => 'Blokkot csak meglévő blokk mellé lehet rakni.']);
}

$world->setBlock($x, $y, $type);
$context['worldRepository']->save($world);

respond(200, [
    'x' => $x,
    'y' => $y,
    'type' => $type->value,
]);

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
