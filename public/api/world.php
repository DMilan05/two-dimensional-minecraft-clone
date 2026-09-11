<?php

declare(strict_types=1);

use Voxel\GameRules;

$context = require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$world = $context['worldProvider']->loadOrCreate();
$player = $context['playerProvider']->loadOrCreate($world);

echo json_encode([
    'world' => $world->toArray(),
    'surfaceLine' => $context['worldProvider']->getSurfaceLine(),
    'player' => $player->toArray(),
    'rules' => GameRules::toArray(),
], JSON_THROW_ON_ERROR);
