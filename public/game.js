const BLOCK_SIZE = 16;

const BlockType = {
    Air: 0,
    Dirt: 1,
    Stone: 2,
    Grass: 3,
    Sand: 4,
    Wood: 5,
    Leaves: 6,
    Bedrock: 7,
    DoorClosedBottom: 8,
    DoorClosedTop: 9,
    DoorOpenBottom: 10,
    DoorOpenTop: 11,
    Torch: 12,
};

const COLORS = {
    [BlockType.Air]: '#87CEEB',
    [BlockType.Dirt]: '#5f4c3d',
    [BlockType.Stone]: '#42494a',
    [BlockType.Grass]: '#4a913f',
    [BlockType.Sand]: '#d8c48f',
    [BlockType.Wood]: '#6b4a2b',
    [BlockType.Leaves]: '#347a2b',
    [BlockType.Bedrock]: '#1b1d1f',
    [BlockType.DoorClosedBottom]: '#8a5a2b',
    [BlockType.DoorClosedTop]: '#8a5a2b',
    [BlockType.DoorOpenBottom]: '#8a5a2b',
    [BlockType.DoorOpenTop]: '#8a5a2b',
    [BlockType.Torch]: '#f5c451',
};

const SKY_COLOR = COLORS[BlockType.Air];

/**
 * What you see through an air cell that is not open to the sky: the far wall
 * of the cave rather than the sky itself.
 */
const CAVE_BACKGROUND = 'cave';

COLORS[CAVE_BACKGROUND] = '#3a2e26';

const BLOCK_NAMES = {
    [BlockType.Dirt]: 'Föld',
    [BlockType.Stone]: 'Kő',
    [BlockType.Grass]: 'Fű',
    [BlockType.Sand]: 'Homok',
    [BlockType.Wood]: 'Fa',
    [BlockType.Leaves]: 'Levél',
    [BlockType.DoorClosedBottom]: 'Ajtó',
    [BlockType.Torch]: 'Fáklya',
};

/** Slots in the order they appear on screen; the index is the number key. */
const HOTBAR_SLOTS = [
    BlockType.Dirt,
    BlockType.Stone,
    BlockType.Grass,
    BlockType.Sand,
    BlockType.Wood,
    BlockType.Leaves,
    BlockType.DoorClosedBottom,
    BlockType.Torch,
];

const HOTBAR_SLOT_SIZE = 34;
const HOTBAR_PADDING = 8;

// Physics, expressed in blocks and seconds so the numbers stay readable.
const MOVE_SPEED = 7;
const JUMP_SPEED = 11.5;
const GRAVITY = 34;
const FLY_SPEED = 9;
const TERMINAL_VELOCITY = 30;
const EPSILON = 0.0001;

// How often the browser tells the server where the player is.
const POSITION_SYNC_INTERVAL = 500;

const canvas = document.getElementById('game');
const ctx = canvas.getContext('2d');
const statusLine = document.getElementById('status');

const VIEW_WIDTH = canvas.width / BLOCK_SIZE;
const VIEW_HEIGHT = canvas.height / BLOCK_SIZE;

let state = null;
let player = null;
let selectedType = BlockType.Dirt;
let hoveredBlock = null;
let lastFrameTime = 0;
let lastSyncedAt = 0;
let syncInFlight = null;

/**
 * Which types are passable, transparent, doors, or light sources all come from
 * the server, so the enum stays the single source of truth for both sides.
 */
let nonSolidTypes = new Set([BlockType.Air]);
let transparentTypes = new Set([BlockType.Air]);
let doorTypes = new Set();
let lightEmission = {};

/** Block type value => how many the player carries. Ignored in creative. */
let inventory = {};
let gameMode = 'survival';

/**
 * Two separate light channels, as in Minecraft. Sunlight fades at night;
 * torchlight does not. Keeping them apart means the day-night cycle needs no
 * recomputation - only the final brightness per cell changes.
 */
let skyLight = null;
let blockLight = null;

/**
 * Where the ground was when the world was generated. Digging does not move it,
 * which is exactly why the background needs it: sunlight reaches straight down
 * a freshly dug shaft, but you are still underground.
 */
let surfaceLine = [];

/** COLORS scaled to every light level, built once to avoid per-frame work. */
let shadeCache = {};

/**
 * Top-left corner of the visible area, in whole pixels. Keeping it integral
 * means every block lands on an exact pixel boundary, with no seams between
 * neighbouring rectangles.
 */
const camera = { pixelX: 0, pixelY: 0 };

const pressedKeys = new Set();

/* ------------------------------------------------------------------ *
 * World helpers
 * ------------------------------------------------------------------ */

function blockTypeAt(x, y) {
    if (y < 0) {
        return BlockType.Air;
    }

    if (x < 0 || x >= state.world.width || y >= state.world.height) {
        return BlockType.Stone; // Treat the world edges as walls.
    }

    return state.world.grid[y][x];
}

function isSolidAt(x, y) {
    return !nonSolidTypes.has(blockTypeAt(x, y));
}

/**
 * True when the player's bounding box overlaps any solid block.
 */
function playerCollides() {
    const left = Math.floor(player.x);
    const right = Math.floor(player.x + state.rules.playerWidth - EPSILON);
    const top = Math.floor(player.y);
    const bottom = Math.floor(player.y + state.rules.playerHeight - EPSILON);

    for (let y = top; y <= bottom; y++) {
        for (let x = left; x <= right; x++) {
            if (isSolidAt(x, y)) {
                return true;
            }
        }
    }

    return false;
}

/* ------------------------------------------------------------------ *
 * Lighting
 * ------------------------------------------------------------------ */

/**
 * Spreads light outwards from the cells already in the queue. Each step costs
 * one light level, so a source of 15 reaches 15 blocks at most.
 *
 * An opaque block is lit by its neighbours - otherwise every wall would be a
 * black silhouette - but it does not pass light on.
 */
function spreadLight(map, queue) {
    const width = state.world.width;
    const height = state.world.height;

    let head = 0;

    while (head < queue.length) {
        const index = queue[head];
        head++;

        const level = map[index];

        if (level <= 1) {
            continue;
        }

        const x = index % width;
        const y = (index - x) / width;
        const next = level - 1;

        const neighbours = [
            [x - 1, y],
            [x + 1, y],
            [x, y - 1],
            [x, y + 1],
        ];

        for (const [nx, ny] of neighbours) {
            if (nx < 0 || ny < 0 || nx >= width || ny >= height) {
                continue;
            }

            const neighbourIndex = ny * width + nx;

            if (map[neighbourIndex] >= next) {
                continue;
            }

            map[neighbourIndex] = next;

            if (transparentTypes.has(state.world.grid[ny][nx])) {
                queue.push(neighbourIndex);
            }
        }
    }
}

/**
 * Rebuilds both light maps from scratch. Cheap enough to run after every
 * change: a few tens of thousands of cells is nothing for a flood fill.
 */
function computeLight() {
    const width = state.world.width;
    const height = state.world.height;
    const max = state.rules.maxLightLevel;

    skyLight = new Uint8Array(width * height);
    blockLight = new Uint8Array(width * height);

    const skyQueue = [];
    const blockQueue = [];

    for (let x = 0; x < width; x++) {
        // Sunlight falls straight down until something opaque stops it.
        for (let y = 0; y < height; y++) {
            if (!transparentTypes.has(state.world.grid[y][x])) {
                break;
            }

            const index = y * width + x;
            skyLight[index] = max;
            skyQueue.push(index);
        }
    }

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const emission = lightEmission[state.world.grid[y][x]];

            if (emission === undefined) {
                continue;
            }

            const index = y * width + x;
            blockLight[index] = emission;
            blockQueue.push(index);
        }
    }

    spreadLight(skyLight, skyQueue);
    spreadLight(blockLight, blockQueue);
}

/**
 * How bright the sun is right now, between nightBrightness and 1.
 */
function daylightFactor(timestamp) {
    const cycle = (timestamp / 1000) % state.rules.dayLengthSeconds;
    const phase = cycle / state.rules.dayLengthSeconds;

    // Shifted so the cycle starts at noon rather than at dawn.
    const sun = (Math.sin(phase * Math.PI * 2 + Math.PI / 2) + 1) / 2;

    return state.rules.nightBrightness + (1 - state.rules.nightBrightness) * sun;
}

function lightLevelAt(x, y, daylight) {
    const index = y * state.world.width + x;

    return Math.round(Math.max(blockLight[index], skyLight[index] * daylight));
}

/**
 * Pre-multiplies every block colour by every light level, so drawing only ever
 * looks a string up instead of building one per cell per frame.
 */
function buildShadeCache() {
    const max = state.rules.maxLightLevel;
    const floor = state.rules.minBrightness;

    shadeCache = {};

    for (const [type, hex] of Object.entries(COLORS)) {
        const red = parseInt(hex.slice(1, 3), 16);
        const green = parseInt(hex.slice(3, 5), 16);
        const blue = parseInt(hex.slice(5, 7), 16);

        shadeCache[type] = [];

        for (let level = 0; level <= max; level++) {
            const brightness = Math.max(floor, level / max);

            shadeCache[type].push(
                `rgb(${Math.round(red * brightness)}, ${Math.round(green * brightness)}, ${Math.round(blue * brightness)})`,
            );
        }
    }
}

/* ------------------------------------------------------------------ *
 * Physics
 * ------------------------------------------------------------------ */

function updatePhysics(dt) {
    const left = pressedKeys.has('a') || pressedKeys.has('arrowleft');
    const right = pressedKeys.has('d') || pressedKeys.has('arrowright');
    const up = pressedKeys.has('w') || pressedKeys.has(' ') || pressedKeys.has('arrowup');
    const down = pressedKeys.has('s') || pressedKeys.has('arrowdown');

    player.vx = (Number(right) - Number(left)) * MOVE_SPEED;

    if (gameMode === 'creative') {
        // No gravity while flying: vertical speed comes straight from the keys,
        // and stays at zero when neither is held.
        player.vy = (Number(down) - Number(up)) * FLY_SPEED;
    } else {
        if (up && player.onGround) {
            player.vy = -JUMP_SPEED;
            player.onGround = false;
        }

        player.vy = Math.min(player.vy + GRAVITY * dt, TERMINAL_VELOCITY);
    }

    moveHorizontally(player.vx * dt);
    moveVertically(player.vy * dt);
}

function moveHorizontally(dx) {
    if (dx === 0) {
        return;
    }

    player.x += dx;

    if (!playerCollides()) {
        return;
    }

    if (dx > 0) {
        player.x = Math.floor(player.x + state.rules.playerWidth) - state.rules.playerWidth - EPSILON;
    } else {
        player.x = Math.floor(player.x) + 1 + EPSILON;
    }

    player.vx = 0;
}

function moveVertically(dy) {
    if (dy === 0) {
        return;
    }

    player.y += dy;
    player.onGround = false;

    if (!playerCollides()) {
        return;
    }

    if (dy > 0) {
        player.y = Math.floor(player.y + state.rules.playerHeight) - state.rules.playerHeight - EPSILON;
        player.onGround = true;
    } else {
        player.y = Math.floor(player.y) + 1 + EPSILON;
    }

    player.vy = 0;
}

/* ------------------------------------------------------------------ *
 * Camera
 * ------------------------------------------------------------------ */

function clamp(value, min, max) {
    // When the world is smaller than the view, max drops below min; the view
    // is then pinned to the world's top-left corner.
    if (max < min) {
        return min;
    }

    return Math.min(Math.max(value, min), max);
}

function updateCamera() {
    const centreX = player.x + state.rules.playerWidth / 2;
    const centreY = player.y + state.rules.playerHeight / 2;

    const targetX = clamp(centreX - VIEW_WIDTH / 2, 0, state.world.width - VIEW_WIDTH);
    const targetY = clamp(centreY - VIEW_HEIGHT / 2, 0, state.world.height - VIEW_HEIGHT);

    camera.pixelX = Math.round(targetX * BLOCK_SIZE);
    camera.pixelY = Math.round(targetY * BLOCK_SIZE);
}

/* ------------------------------------------------------------------ *
 * Drawing
 * ------------------------------------------------------------------ */

/**
 * A closed door fills its cell; an open one is drawn as a narrow panel swung
 * to the side, so you can see at a glance whether you can walk through.
 */
function drawDoor(type, screenX, screenY, colour) {
    const open = type === BlockType.DoorOpenBottom || type === BlockType.DoorOpenTop;
    const width = open ? BLOCK_SIZE : BLOCK_SIZE / 4;

    ctx.fillStyle = colour;
    ctx.fillRect(screenX, screenY, width, BLOCK_SIZE);

    if (!open) {
        return;
    }

    ctx.strokeStyle = '#5c3a1a';
    ctx.lineWidth = 1;
    ctx.strokeRect(screenX + 0.5, screenY + 0.5, BLOCK_SIZE - 1, BLOCK_SIZE - 1);

    if (type === BlockType.DoorOpenBottom) {
        ctx.fillStyle = '#e0c060';
        ctx.fillRect(screenX + BLOCK_SIZE - 5, screenY + 4, 2, 2);
    }
}

/**
 * A torch lights itself, so it is drawn at full brightness regardless of the
 * surrounding light level.
 */
function drawTorch(screenX, screenY) {
    ctx.fillStyle = '#6b4a2b';
    ctx.fillRect(screenX + BLOCK_SIZE / 2 - 1, screenY + 5, 2, BLOCK_SIZE - 5);

    ctx.fillStyle = COLORS[BlockType.Torch];
    ctx.fillRect(screenX + BLOCK_SIZE / 2 - 2, screenY + 2, 4, 4);
}

/**
 * Whether an air cell shows sky rather than the inside of a wall.
 *
 * Two conditions, and both are needed. Above the original ground line rules
 * out caves and dug shafts. Some sunlight reaching the cell rules out sealed
 * rooms - and, because sunlight also spreads sideways, it still counts the air
 * under a tree as outdoors.
 */
function isOutdoors(x, y) {
    if (surfaceLine.length === 0) {
        return true;
    }

    return y < surfaceLine[x] && skyLight[y * state.world.width + x] > 0;
}

function drawWorld(daylight) {
    // The sky itself dims at night, otherwise the horizon would stay bright
    // while everything below it went dark.
    const skyBrightness = Math.max(state.rules.minBrightness, daylight);
    ctx.fillStyle = shadeCache[BlockType.Air][Math.round(skyBrightness * state.rules.maxLightLevel)];
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    const firstX = Math.max(0, Math.floor(camera.pixelX / BLOCK_SIZE));
    const firstY = Math.max(0, Math.floor(camera.pixelY / BLOCK_SIZE));
    const lastX = Math.min(state.world.width - 1, Math.ceil((camera.pixelX + canvas.width) / BLOCK_SIZE));
    const lastY = Math.min(state.world.height - 1, Math.ceil((camera.pixelY + canvas.height) / BLOCK_SIZE));

    for (let y = firstY; y <= lastY; y++) {
        for (let x = firstX; x <= lastX; x++) {
            const type = state.world.grid[y][x];
            const screenX = x * BLOCK_SIZE - camera.pixelX;
            const screenY = y * BLOCK_SIZE - camera.pixelY;

            if (type === BlockType.Air) {
                if (isOutdoors(x, y)) {
                    // The background fill already painted the sky here.
                    continue;
                }

                ctx.fillStyle = shadeCache[CAVE_BACKGROUND][lightLevelAt(x, y, daylight)];
                ctx.fillRect(screenX, screenY, BLOCK_SIZE, BLOCK_SIZE);
                continue;
            }

            if (type === BlockType.Torch) {
                drawTorch(screenX, screenY);
                continue;
            }

            const colour = shadeCache[type][lightLevelAt(x, y, daylight)];

            if (doorTypes.has(type)) {
                drawDoor(type, screenX, screenY, colour);
                continue;
            }

            ctx.fillStyle = colour;
            ctx.fillRect(screenX, screenY, BLOCK_SIZE, BLOCK_SIZE);
        }
    }
}

function drawPlayer(daylight) {
    const width = state.rules.playerWidth * BLOCK_SIZE;
    const height = state.rules.playerHeight * BLOCK_SIZE;
    const screenX = player.x * BLOCK_SIZE - camera.pixelX;
    const screenY = player.y * BLOCK_SIZE - camera.pixelY;

    // The player is lit by whatever cell its head is in.
    const headX = clamp(Math.floor(player.x), 0, state.world.width - 1);
    const headY = clamp(Math.floor(player.y), 0, state.world.height - 1);
    const level = lightLevelAt(headX, headY, daylight);
    const brightness = Math.max(state.rules.minBrightness, level / state.rules.maxLightLevel);

    ctx.fillStyle = `rgb(${Math.round(47 * brightness)}, ${Math.round(54 * brightness)}, ${Math.round(64 * brightness)})`;
    ctx.fillRect(screenX, screenY + height * 0.35, width, height * 0.65);

    ctx.fillStyle = `rgb(${Math.round(232 * brightness)}, ${Math.round(176 * brightness)}, ${Math.round(122 * brightness)})`;
    ctx.fillRect(screenX, screenY, width, height * 0.35);
}

function drawCursor() {
    if (hoveredBlock === null) {
        return;
    }

    ctx.strokeStyle = isWithinReach(hoveredBlock) ? '#ffffff' : '#c0392b';
    ctx.lineWidth = 2;
    ctx.strokeRect(
        hoveredBlock.x * BLOCK_SIZE - camera.pixelX + 1,
        hoveredBlock.y * BLOCK_SIZE - camera.pixelY + 1,
        BLOCK_SIZE - 2,
        BLOCK_SIZE - 2,
    );
}

/**
 * A row of coloured slots along the bottom of the canvas. Drawn last so it
 * always sits on top of the world.
 */
function drawHotbar() {
    const totalWidth = HOTBAR_SLOTS.length * (HOTBAR_SLOT_SIZE + HOTBAR_PADDING) - HOTBAR_PADDING;
    const startX = HOTBAR_PADDING;
    const startY = canvas.height - HOTBAR_SLOT_SIZE - HOTBAR_PADDING;

    ctx.fillStyle = 'rgba(0, 0, 0, 0.35)';
    ctx.fillRect(
        startX - HOTBAR_PADDING / 2,
        startY - HOTBAR_PADDING / 2,
        totalWidth + HOTBAR_PADDING,
        HOTBAR_SLOT_SIZE + HOTBAR_PADDING,
    );

    ctx.font = '11px sans-serif';
    ctx.textBaseline = 'top';

    const creative = gameMode === 'creative';

    HOTBAR_SLOTS.forEach((type, index) => {
        const x = startX + index * (HOTBAR_SLOT_SIZE + HOTBAR_PADDING);
        const count = inventory[type] ?? 0;
        const usable = creative || count > 0;

        // An empty slot is drawn faded, so it is obvious why placing fails.
        ctx.globalAlpha = usable ? 1 : 0.3;
        ctx.fillStyle = COLORS[type];
        ctx.fillRect(x, startY, HOTBAR_SLOT_SIZE, HOTBAR_SLOT_SIZE);
        ctx.globalAlpha = 1;

        ctx.strokeStyle = type === selectedType ? '#ffffff' : 'rgba(0, 0, 0, 0.6)';
        ctx.lineWidth = type === selectedType ? 3 : 1;
        ctx.strokeRect(x + 0.5, startY + 0.5, HOTBAR_SLOT_SIZE - 1, HOTBAR_SLOT_SIZE - 1);

        ctx.fillStyle = '#ffffff';
        ctx.fillText(String(index + 1), x + 3, startY + 2);

        if (!creative) {
            ctx.textAlign = 'right';
            ctx.fillText(String(count), x + HOTBAR_SLOT_SIZE - 3, startY + HOTBAR_SLOT_SIZE - 13);
            ctx.textAlign = 'left';
        }
    });
}

function render(daylight) {
    drawWorld(daylight);
    drawPlayer(daylight);
    drawCursor();
    drawHotbar();
}

/* ------------------------------------------------------------------ *
 * Reach
 * ------------------------------------------------------------------ */

function isWithinReach(block) {
    const centreX = player.x + state.rules.playerWidth / 2;
    const centreY = player.y + state.rules.playerHeight / 2;

    const dx = block.x + 0.5 - centreX;
    const dy = block.y + 0.5 - centreY;

    return Math.sqrt(dx * dx + dy * dy) <= state.rules.reach;
}

/* ------------------------------------------------------------------ *
 * Server communication
 * ------------------------------------------------------------------ */

function showStatus(message) {
    statusLine.textContent = message;
}

function showSelection() {
    const modeLabel = gameMode === 'creative' ? 'Kreatív' : 'Túlélő';

    showStatus(`Mód: ${modeLabel} · Kiválasztott blokk: ${BLOCK_NAMES[selectedType]}`);
}

/**
 * Pushes the current position to the server. The server validates reach
 * against this stored position, so it is flushed before every action.
 */
async function syncPosition() {
    if (syncInFlight !== null) {
        return syncInFlight;
    }

    syncInFlight = fetch('api/player.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ x: player.x, y: player.y }),
    })
        .catch((error) => console.error(error))
        .finally(() => {
            syncInFlight = null;
            lastSyncedAt = performance.now();
        });

    return syncInFlight;
}

async function sendAction(endpoint, payload) {
    if (state === null) {
        return;
    }

    // The server checks reach against the last position it knows about.
    await syncPosition();

    try {
        const response = await fetch(`api/${endpoint}.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });

        const data = await response.json();

        if (!response.ok) {
            showStatus(data.error ?? 'Ismeretlen hiba történt.');
            return;
        }

        // One action can change several cells: a door is two, and falling sand
        // is a pair of cells per block that moved.
        data.changes.forEach((change) => {
            state.world.grid[change.y][change.x] = change.type;
        });

        if (data.changes.length > 0) {
            computeLight();
        }

        if (data.inventory !== undefined) {
            inventory = data.inventory;
        }

        if (data.mode !== undefined) {
            gameMode = data.mode;
        }

        // Placing a block under your own feet lifts you onto it; the server
        // works out the new height so both sides agree on where you are.
        if (data.playerY !== undefined && data.playerY !== null) {
            player.y = data.playerY;
            player.vy = 0;
            player.onGround = true;
        }

        showSelection();
    } catch (error) {
        showStatus('Nem sikerült elérni a szervert.');
        console.error(error);
    }
}

/* ------------------------------------------------------------------ *
 * Input
 * ------------------------------------------------------------------ */

/**
 * Screen pixels to world block coordinates. The camera offset is what makes
 * this different from the pre-camera version.
 */
function blockAt(event) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;

    const canvasX = (event.clientX - rect.left) * scaleX;
    const canvasY = (event.clientY - rect.top) * scaleY;

    return {
        x: Math.floor((canvasX + camera.pixelX) / BLOCK_SIZE),
        y: Math.floor((canvasY + camera.pixelY) / BLOCK_SIZE),
    };
}

canvas.addEventListener('mousemove', (event) => {
    hoveredBlock = blockAt(event);
});

canvas.addEventListener('mouseleave', () => {
    hoveredBlock = null;
});

canvas.addEventListener('click', (event) => {
    const { x, y } = blockAt(event);
    sendAction('break', { x, y });
});

canvas.addEventListener('contextmenu', (event) => {
    event.preventDefault();

    const { x, y } = blockAt(event);

    // Right-clicking a door opens or closes it instead of placing a block.
    if (doorTypes.has(blockTypeAt(x, y))) {
        sendAction('interact', { x, y });
        return;
    }

    sendAction('place', { x, y, type: selectedType });
});

window.addEventListener('keydown', (event) => {
    const key = event.key.toLowerCase();

    pressedKeys.add(key);

    const slot = Number(key);

    if (Number.isInteger(slot) && slot >= 1 && slot <= HOTBAR_SLOTS.length) {
        selectedType = HOTBAR_SLOTS[slot - 1];
        showSelection();
    }

    if (key === 'g') {
        sendAction('mode', {});
    }

    // Stop the page from scrolling while playing.
    if ([' ', 'arrowup', 'arrowdown', 'arrowleft', 'arrowright'].includes(key)) {
        event.preventDefault();
    }
});

window.addEventListener('keyup', (event) => {
    pressedKeys.delete(event.key.toLowerCase());
});

window.addEventListener('blur', () => pressedKeys.clear());

/* ------------------------------------------------------------------ *
 * Main loop
 * ------------------------------------------------------------------ */

function loop(timestamp) {
    // Clamped so a background tab does not teleport the player on return.
    const dt = Math.min((timestamp - lastFrameTime) / 1000, 0.05);
    lastFrameTime = timestamp;

    updatePhysics(dt);
    updateCamera();
    render(daylightFactor(timestamp));

    if (timestamp - lastSyncedAt > POSITION_SYNC_INTERVAL) {
        syncPosition();
    }

    requestAnimationFrame(loop);
}

fetch('api/world.php')
    .then((response) => response.json())
    .then((data) => {
        state = data;
        nonSolidTypes = new Set(data.rules.nonSolidTypes);
        transparentTypes = new Set(data.rules.transparentTypes);
        doorTypes = new Set(data.rules.doorTypes);
        lightEmission = data.rules.lightEmission ?? {};
        surfaceLine = data.surfaceLine ?? [];
        inventory = data.player.inventory ?? {};
        gameMode = data.player.mode ?? 'survival';

        player = {
            x: data.player.x,
            y: data.player.y,
            vx: 0,
            vy: 0,
            onGround: false,
        };

        buildShadeCache();
        computeLight();
        showSelection();
        updateCamera();

        lastFrameTime = performance.now();
        lastSyncedAt = lastFrameTime;
        requestAnimationFrame(loop);
    })
    .catch((error) => {
        showStatus('Nem sikerült betölteni a világot.');
        console.error(error);
    });
