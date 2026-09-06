<?php

declare(strict_types=1);

namespace Voxel;

/**
 * Rules both the server and the browser need to agree on. Sending the type
 * lists along with the world means the browser never has to keep its own copy
 * of which blocks are solid - one source of truth, in the enum.
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

    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        $nonSolid = [];
        $doors = [];

        foreach (BlockType::cases() as $type) {
            if (!$type->isSolid()) {
                $nonSolid[] = $type->value;
            }

            if ($type->isDoor()) {
                $doors[] = $type->value;
            }
        }

        return [
            'reach' => self::REACH,
            'playerWidth' => self::PLAYER_WIDTH,
            'playerHeight' => self::PLAYER_HEIGHT,
            'nonSolidTypes' => $nonSolid,
            'doorTypes' => $doors,
        ];
    }
}
