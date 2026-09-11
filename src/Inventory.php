<?php

declare(strict_types=1);

namespace Voxel;

use InvalidArgumentException;

/**
 * How many of each block type the player is carrying. There are no stacks or
 * slot limits: a plain map from block type to a count is enough for now, and
 * it is what the browser needs to render the hotbar numbers.
 */
final class Inventory
{
    /** @var array<int, int> block type value => count, never zero */
    private array $counts = [];

    /**
     * @param array<int|string, int|string> $counts
     */
    public function __construct(array $counts = [])
    {
        foreach ($counts as $typeValue => $count) {
            $type = BlockType::tryFrom((int) $typeValue);

            if ($type === null) {
                continue; // Silently drop types a newer save no longer knows.
            }

            $this->add($type, (int) $count);
        }
    }

    public function add(BlockType $type, int $amount = 1): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("Amount must be positive, got {$amount}");
        }

        $this->counts[$type->value] = $this->count($type) + $amount;
    }

    /**
     * Removes the given amount and reports whether there was enough. Nothing
     * is taken when the answer is no.
     */
    public function remove(BlockType $type, int $amount = 1): bool
    {
        if ($this->count($type) < $amount) {
            return false;
        }

        $remaining = $this->count($type) - $amount;

        if ($remaining === 0) {
            unset($this->counts[$type->value]);
        } else {
            $this->counts[$type->value] = $remaining;
        }

        return true;
    }

    public function count(BlockType $type): int
    {
        return $this->counts[$type->value] ?? 0;
    }

    /**
     * @return array<int, int>
     */
    public function toArray(): array
    {
        return $this->counts;
    }
}
