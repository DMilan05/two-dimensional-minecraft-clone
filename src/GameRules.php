<?php

declare(strict_types=1);

namespace Voxel;

/**
 * Rules both the server and the browser need to agree on. Sending the type
 * lists along with the world means the browser never has to keep its own copy
 * of which blocks are solid - one source of truth, in the enum.
 *
 * Light is computed in the browser rather than here, because it is a pure
 * function of the grid: the browser already has the grid, so recomputing is
 * cheaper than shipping a second grid of light values over the wire after
 * every click. The rules it needs to do that travel in this payload.
 */
final class GameRules
{
    /** How far the player can reach, measured in blocks from its centre. */
    public const REACH = 4.0;

    /** Player size in blocks. Narrower than one block so it fits through gaps. */
    public const PLAYER_WIDTH = 0.9;
    public const PLAYER_HEIGHT = 1.8;

    /**
     * When true, a new block only needs any solid neighbour (Minecraft rules).
     * Set it to false to require solid ground directly underneath instead.
     */
    public const ALLOW_SIDEWAYS_PLACEMENT = true;

    /** Brightest light level; every block of distance costs one. */
    public const MAX_LIGHT_LEVEL = 15;

    /** Length of a full day-night cycle, in seconds. */
    public const DAY_LENGTH_SECONDS = 240;

    /** How much of the sunlight still reaches the ground at midnight. */
    public const NIGHT_BRIGHTNESS = 0.18;

    /** Floor brightness, so unlit caves stay readable instead of pure black. */
    public const MIN_BRIGHTNESS = 0.06;

    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        $nonSolid = [];
        $transparent = [];
        $doors = [];
        $emission = [];

        foreach (BlockType::cases() as $type) {
            if (!$type->isSolid()) {
                $nonSolid[] = $type->value;
            }

            if ($type->isTransparent()) {
                $transparent[] = $type->value;
            }

            if ($type->isDoor()) {
                $doors[] = $type->value;
            }

            if ($type->lightEmission() > 0) {
                $emission[$type->value] = $type->lightEmission();
            }
        }

        return [
            'reach' => self::REACH,
            'playerWidth' => self::PLAYER_WIDTH,
            'playerHeight' => self::PLAYER_HEIGHT,
            'nonSolidTypes' => $nonSolid,
            'transparentTypes' => $transparent,
            'doorTypes' => $doors,
            'lightEmission' => $emission,
            'maxLightLevel' => self::MAX_LIGHT_LEVEL,
            'dayLengthSeconds' => self::DAY_LENGTH_SECONDS,
            'nightBrightness' => self::NIGHT_BRIGHTNESS,
            'minBrightness' => self::MIN_BRIGHTNESS,
        ];
    }
}
