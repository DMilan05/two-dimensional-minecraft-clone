<?php

declare(strict_types=1);

namespace Voxel;

use RuntimeException;

/**
 * Stores a world as a directory of chunk files instead of one large document.
 *
 * The whole world is still held in memory and sent to the browser in one
 * piece; what chunking buys here is write cost. Breaking a single block used
 * to rewrite every byte of the world - now it rewrites one chunk.
 *
 * Layout:
 *   <directory>/meta.json          - size and format version
 *   <directory>/chunk_<x>_<y>.json - rows of block values for one chunk
 */
final class WorldRepository
{
    private const CHUNK_SIZE = 32;

    public function __construct(private readonly string $directory)
    {
    }

    public function exists(): bool
    {
        return is_file($this->metaPath());
    }

    /**
     * Writes the metadata and every chunk. Used when a world is first
     * generated.
     */
    /**
     * @param int[] $surfaceLine
     */
    public function saveAll(World $world, array $surfaceLine): void
    {
        $this->ensureDirectoryExists();
        $this->writeMeta($world, $surfaceLine);

        foreach ($this->allChunks($world) as [$chunkX, $chunkY]) {
            $this->writeChunk($world, $chunkX, $chunkY);
        }
    }

    /**
     * Writes only the chunks the given cells fall into.
     *
     * The endpoints already build a list of changed cells for the browser, so
     * the repository can reuse it instead of tracking dirty state itself.
     *
     * @param list<array{x: int, y: int, type: int}> $changes
     */
    public function saveChanges(World $world, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $this->ensureDirectoryExists();

        $dirty = [];

        foreach ($changes as $change) {
            $chunkX = intdiv($change['x'], self::CHUNK_SIZE);
            $chunkY = intdiv($change['y'], self::CHUNK_SIZE);

            // The string key deduplicates: several changes usually land in the
            // same chunk, and that chunk should only be written once.
            $dirty["{$chunkX}:{$chunkY}"] = [$chunkX, $chunkY];
        }

        foreach ($dirty as [$chunkX, $chunkY]) {
            $this->writeChunk($world, $chunkX, $chunkY);
        }
    }

    public function load(): World
    {
        $meta = $this->readJson($this->metaPath());

        if (($meta['version'] ?? null) !== World::FORMAT_VERSION) {
            throw new RuntimeException('Unsupported world format version.');
        }

        if (($meta['chunkSize'] ?? null) !== self::CHUNK_SIZE) {
            throw new RuntimeException('The saved world uses a different chunk size.');
        }

        $world = new World((int) $meta['width'], (int) $meta['height']);

        foreach ($this->allChunks($world) as [$chunkX, $chunkY]) {
            $this->readChunk($world, $chunkX, $chunkY);
        }

        return $world;
    }

    /**
     * Every chunk coordinate covering the world, row by row.
     *
     * @return iterable<array{int, int}>
     */
    private function allChunks(World $world): iterable
    {
        $chunksX = (int) ceil($world->getWidth() / self::CHUNK_SIZE);
        $chunksY = (int) ceil($world->getHeight() / self::CHUNK_SIZE);

        for ($chunkY = 0; $chunkY < $chunksY; $chunkY++) {
            for ($chunkX = 0; $chunkX < $chunksX; $chunkX++) {
                yield [$chunkX, $chunkY];
            }
        }
    }

    /**
     * Reads back the original surface line. Worlds saved before this field
     * existed fall back to the current terrain, which is close enough for an
     * untouched world and wrong only where the player has already dug.
     *
     * @return int[]
     */
    public function loadSurfaceLine(World $world): array
    {
        $meta = $this->readJson($this->metaPath());
        $line = $meta['surfaceLine'] ?? null;

        if (is_array($line) && count($line) === $world->getWidth()) {
            return array_map(intval(...), $line);
        }

        return $this->deriveSurfaceLine($world);
    }

    /**
     * The row of the topmost solid block in each column.
     *
     * @return int[]
     */
    private function deriveSurfaceLine(World $world): array
    {
        $line = [];

        for ($x = 0; $x < $world->getWidth(); $x++) {
            $line[$x] = $world->getHeight();

            for ($y = 0; $y < $world->getHeight(); $y++) {
                if ($world->getBlock($x, $y)->isSolid()) {
                    $line[$x] = $y;
                    break;
                }
            }
        }

        return $line;
    }

    /**
     * @param int[] $surfaceLine
     */
    private function writeMeta(World $world, array $surfaceLine): void
    {
        $meta = [
            'version' => World::FORMAT_VERSION,
            'width' => $world->getWidth(),
            'height' => $world->getHeight(),
            'chunkSize' => self::CHUNK_SIZE,
            'surfaceLine' => $surfaceLine,
        ];

        $this->writeFile($this->metaPath(), json_encode($meta, JSON_THROW_ON_ERROR));
    }

    private function writeChunk(World $world, int $chunkX, int $chunkY): void
    {
        [$startX, $startY, $endX, $endY] = $this->chunkBounds($world, $chunkX, $chunkY);

        $rows = [];

        for ($y = $startY; $y < $endY; $y++) {
            $row = [];

            for ($x = $startX; $x < $endX; $x++) {
                $row[] = $world->getBlock($x, $y)->value;
            }

            $rows[] = $row;
        }

        $this->writeFile($this->chunkPath($chunkX, $chunkY), json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function readChunk(World $world, int $chunkX, int $chunkY): void
    {
        $path = $this->chunkPath($chunkX, $chunkY);

        if (!is_file($path)) {
            throw new RuntimeException("Missing chunk file: {$path}");
        }

        $rows = $this->readJson($path);
        [$startX, $startY] = $this->chunkBounds($world, $chunkX, $chunkY);

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $world->setBlock(
                    $startX + $columnIndex,
                    $startY + $rowIndex,
                    BlockType::from((int) $value),
                );
            }
        }
    }

    /**
     * The world is rarely an exact multiple of the chunk size, so the chunks
     * along the right and bottom edges are clipped.
     *
     * @return array{int, int, int, int} startX, startY, endX, endY
     */
    private function chunkBounds(World $world, int $chunkX, int $chunkY): array
    {
        $startX = $chunkX * self::CHUNK_SIZE;
        $startY = $chunkY * self::CHUNK_SIZE;

        return [
            $startX,
            $startY,
            min($startX + self::CHUNK_SIZE, $world->getWidth()),
            min($startY + self::CHUNK_SIZE, $world->getHeight()),
        ];
    }

    private function metaPath(): string
    {
        return $this->directory . '/meta.json';
    }

    private function chunkPath(int $chunkX, int $chunkY): string
    {
        return $this->directory . "/chunk_{$chunkX}_{$chunkY}.json";
    }

    /**
     * @return array<mixed>
     */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read file: {$path}");
        }

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write file: {$path}");
        }
    }

    private function ensureDirectoryExists(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($this->directory, 0o777, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create directory: {$this->directory}");
        }
    }
}
