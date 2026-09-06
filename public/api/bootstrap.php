<?php

declare(strict_types=1);

use Voxel\PlayerProvider;
use Voxel\PlayerRepository;
use Voxel\WorldGenerator;
use Voxel\WorldProvider;
use Voxel\WorldRepository;

require __DIR__ . '/../../vendor/autoload.php';

const WORLD_WIDTH = 256;
const WORLD_HEIGHT = 96;
const DATA_DIR = __DIR__ . '/../../data';

/**
 * Sends a JSON response and stops the script.
 *
 * @param array<string, mixed> $payload
 */
function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR);

    exit;
}

/**
 * Reads and decodes the JSON request body.
 *
 * @return array<string, mixed>
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || $raw === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'Request body is not valid JSON.']);
    }

    return is_array($decoded) ? $decoded : [];
}

/**
 * Rejects anything that is not a POST request.
 */
function requirePostMethod(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(405, ['error' => 'Only POST requests are allowed here.']);
    }
}

/**
 * Extracts and validates a pair of integer block coordinates.
 *
 * @param array<string, mixed> $input
 *
 * @return array{int, int}
 */
function readCoordinates(array $input): array
{
    $x = $input['x'] ?? null;
    $y = $input['y'] ?? null;

    if (!is_int($x) || !is_int($y)) {
        respond(400, ['error' => 'Both "x" and "y" must be integers.']);
    }

    return [$x, $y];
}

$worldRepository = new WorldRepository(DATA_DIR . '/world.json');
$worldProvider = new WorldProvider($worldRepository, new WorldGenerator(), WORLD_WIDTH, WORLD_HEIGHT);

$playerRepository = new PlayerRepository(DATA_DIR . '/player.json');
$playerProvider = new PlayerProvider($playerRepository);

return [
    'worldRepository' => $worldRepository,
    'worldProvider' => $worldProvider,
    'playerRepository' => $playerRepository,
    'playerProvider' => $playerProvider,
];
