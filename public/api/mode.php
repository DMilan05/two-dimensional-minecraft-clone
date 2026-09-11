<?php

declare(strict_types=1);

$context = require __DIR__ . '/bootstrap.php';

requirePostMethod();

$world = $context['worldProvider']->loadOrCreate();
$player = $context['playerProvider']->loadOrCreate($world);

$player->toggleMode();

$context['playerRepository']->save($player);

respond(200, [
    'changes' => [],
    'inventory' => $player->getInventory()->toArray(),
    'mode' => $player->getMode()->value,
]);
