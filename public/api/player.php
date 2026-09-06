<?php

declare(strict_types=1);

$context = require __DIR__ . '/bootstrap.php';

requirePostMethod();

$input = readJsonBody();

$x = $input['x'] ?? null;
$y = $input['y'] ?? null;

if (!is_int($x) && !is_float($x)) {
    respond(400, ['error' => 'The "x" field must be a number.']);
}

if (!is_int($y) && !is_float($y)) {
    respond(400, ['error' => 'The "y" field must be a number.']);
}

$world = $context['worldProvider']->loadOrCreate();

// Keep the stored position inside the world even if the client misbehaves.
$x = max(0.0, min((float) $x, $world->getWidth() - 1.0));
$y = max(0.0, min((float) $y, $world->getHeight() - 1.0));

$player = $context['playerProvider']->loadOrCreate($world);
$player->moveTo($x, $y);

$context['playerRepository']->save($player);

respond(200, $player->toArray());
