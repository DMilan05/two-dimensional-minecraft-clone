# PHP Voxel

A 2D voxel sandbox game — a small Minecraft-style world you can dig, build and
walk around in — written in plain PHP 8 with no framework, and rendered in the
browser on an HTML canvas.

The server owns the world and the player: it generates the terrain, validates
every action and persists the result to JSON files. The browser owns everything
that is a pure function of the grid it already has — rendering, lighting,
physics and the camera — so a single broken block costs one small HTTP round
trip instead of a full world download.

The code, comments and identifiers are in English; the text shown to the player
is in Hungarian.

## Features

- **Procedural terrain** — sinusoidal hills, dirt and stone layers, sand in the
  low columns, an indestructible bedrock floor, random-walk caves and trees.
- **Digging and building** — left click breaks, right click places, with a
  server-side reach check and a support rule for placement.
- **Doors** — two cells tall, opened and closed with right click.
- **Torches and lighting** — two independent light channels (sunlight and block
  light), propagated with a breadth-first flood fill in the browser.
- **Day/night cycle** — a four-minute cycle that dims sunlight only, so a
  torch-lit cave stays just as bright at night.
- **Gravity for sand** — sand settles when the block under it disappears.
- **Survival and creative modes** — survival requires collecting a block before
  placing it; creative ignores the inventory and enables flight.
- **Chunked persistence** — the world is stored as 32×32 chunk files, so
  breaking one block rewrites one chunk rather than the whole world.

## Requirements

- PHP 8.1 or newer (the project is developed on 8.5)
- [Composer](https://getcomposer.org/) — only for the PSR-4 autoloader; the
  project has no runtime dependencies
- A modern browser

## Getting started

```bash
git clone <repository-url> php-voxel
cd php-voxel

# Generates vendor/autoload.php. There is nothing to download.
composer install

# Serve the public directory with PHP's built-in web server.
php -S localhost:8000 -t public
```

Then open <http://localhost:8000> in the browser.

The first request generates a world and writes it to `data/`. Every later
request loads that world, so your changes survive a restart.

## Controls

| Input | Action |
| --- | --- |
| `A` / `D` | Move left and right |
| `W` | Jump |
| `W` / `S` | Fly up and down (creative mode only) |
| Left click | Break a block |
| Right click | Place the selected block, or open/close a door |
| `1`–`8` | Select a hotbar slot |
| `G` | Toggle between survival and creative mode |

The hotbar, in slot order: dirt, stone, grass, sand, wood, leaves, door, torch.

## Project structure

```
public/
  index.html         Canvas and key legend
  game.js            Rendering, lighting, physics, camera, input
  api/
    bootstrap.php    Autoloader, world/player wiring, JSON request helpers
    world.php        GET  — the full world, player and rule set
    break.php        POST — break a block
    place.php        POST — place a block
    interact.php     POST — open or close a door
    mode.php         POST — toggle survival/creative
    player.php       POST — store the player position
src/
  BlockType.php      Block enum and every per-block rule
  GameMode.php       Survival/creative enum
  GameRules.php      Constants both sides need, serialised for the client
  World.php          The block grid, bounds checking, accessors
  WorldGenerator.php Terrain, caves, bedrock, trees
  WorldRepository.php Chunk files and metadata on disk
  WorldProvider.php  Load the saved world, or generate and save a new one
  Player.php         Position, reach, mode, inventory
  PlayerRepository.php / PlayerProvider.php  The same pair for the player
  Inventory.php      Block counts for survival mode
  FallingBlocks.php  Settles gravity-affected blocks in a column
data/                Generated at runtime; not in version control
```

## How it works

**The world lives on the server.** `WorldProvider::loadOrCreate()` returns the
saved world if there is one and generates a fresh one otherwise. Every endpoint
starts from that same call, so there is exactly one world regardless of how many
tabs are open.

**Rules live in the enum.** Whether a block is solid, breakable, placeable,
transparent, a door, or affected by gravity is answered by a method on
`BlockType`. `GameRules::toArray()` serialises the lists the browser needs, so
the client never keeps a second copy of those rules.

**Light is computed in the browser.** Light is a pure function of the grid, and
the browser already has the grid — recomputing it locally is far cheaper than
shipping 24,000 light values after every click. Sunlight and block light are
kept on separate maps so the day/night cycle only has to change a multiplier.

**The surface line decides what is sky.** `meta.json` stores the original ground
height per column. An air cell counts as outdoors only if it is above that line
*and* receives sunlight, which is why a vertical shaft renders as cave wall
rather than sky.

**Writes are chunked.** `WorldRepository::saveChanges()` maps the changed cells
to chunk files and rewrites only those.

## HTTP API

All POST endpoints expect a JSON body and return
`{ "changes": [...], "inventory": {...}, "mode": "survival"|"creative" }`, where
`changes` is the list of `{ x, y, type }` cells the client should repaint.
Errors return a non-2xx status with `{ "error": "..." }`.

| Endpoint | Method | Body | Purpose |
| --- | --- | --- | --- |
| `api/world.php` | GET | — | World grid, surface line, player state, rules |
| `api/break.php` | POST | `{ x, y }` | Break a block; drops go to the inventory |
| `api/place.php` | POST | `{ x, y, type }` | Place a block from the inventory |
| `api/interact.php` | POST | `{ x, y }` | Toggle a door |
| `api/mode.php` | POST | — | Switch game mode |
| `api/player.php` | POST | `{ x, y }` | Persist the player position |

Reach, bounds, support and inventory are all checked server-side; the client is
never trusted.

## Configuration

| Constant | File | Meaning |
| --- | --- | --- |
| `WORLD_WIDTH`, `WORLD_HEIGHT` | `public/api/bootstrap.php` | World size in blocks (256×96) |
| `REACH` | `src/GameRules.php` | How far the player can reach, in blocks |
| `ALLOW_SIDEWAYS_PLACEMENT` | `src/GameRules.php` | Any solid neighbour supports a new block, or only the one below |
| `DAY_LENGTH_SECONDS` | `src/GameRules.php` | Length of a full day/night cycle |
| `NIGHT_BRIGHTNESS`, `MIN_BRIGHTNESS` | `src/GameRules.php` | How dark night and unlit caves get |
| `CAVES_PER_100_COLUMNS`, `TREE_CHANCE_PERCENT`, … | `src/WorldGenerator.php` | Terrain generation tuning |
| `CHUNK_SIZE` | `src/WorldRepository.php` | Chunk file size; changing it invalidates saved worlds |

Changing the world size or chunk size does not migrate existing saves — delete
`data/` first.

## Resetting the world

```bash
rm -rf data/
```

The next request generates a new world and a new player.

## Known limitations

- Single player and single world; there is no locking, so two clients editing
  at once can overwrite each other.
- The whole world is loaded into memory and sent in one response — fine at
  256×96, not at Minecraft scale.
- No mob, crafting or block-hardness system.

## Roadmap

- More block types and a proper HUD
- Water and fluid flow
- Chunked loading over the wire, not just on disk
