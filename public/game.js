const BLOCK_SIZE = 16;

const BlockType = {
    Air: 0,
    Dirt: 1,
    Stone: 2,
    Grass: 3,
};

const COLORS = {
    [BlockType.Air]: '#87CEEB',
    [BlockType.Dirt]: '#5f4c3d',
    [BlockType.Stone]: '#42494a',
    [BlockType.Grass]: '#4a913f',
};

const SKY_COLOR = COLORS[BlockType.Air];

const BLOCK_NAMES = {
    [BlockType.Dirt]: 'Föld',
    [BlockType.Stone]: 'Kő',
    [BlockType.Grass]: 'Fű',
};

const HOTBAR = {
    '1': BlockType.Dirt,
    '2': BlockType.Stone,
    '3': BlockType.Grass,
};

// Physics, expressed in blocks and seconds so the numbers stay readable.
const MOVE_SPEED = 7;
const JUMP_SPEED = 11.5;
const GRAVITY = 34;
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
    return blockTypeAt(x, y) !== BlockType.Air;
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
 * Physics
 * ------------------------------------------------------------------ */

function updatePhysics(dt) {
    const left = pressedKeys.has('a') || pressedKeys.has('arrowleft');
    const right = pressedKeys.has('d') || pressedKeys.has('arrowright');
    const jump = pressedKeys.has('w') || pressedKeys.has(' ') || pressedKeys.has('arrowup');

    player.vx = (Number(right) - Number(left)) * MOVE_SPEED;

    if (jump && player.onGround) {
        player.vy = -JUMP_SPEED;
        player.onGround = false;
    }

    player.vy = Math.min(player.vy + GRAVITY * dt, TERMINAL_VELOCITY);

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

function drawWorld() {
    // The sky fills the canvas first, so air blocks need no rectangle of their
    // own - which is most of the screen above ground.
    ctx.fillStyle = SKY_COLOR;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    const firstX = Math.max(0, Math.floor(camera.pixelX / BLOCK_SIZE));
    const firstY = Math.max(0, Math.floor(camera.pixelY / BLOCK_SIZE));
    const lastX = Math.min(state.world.width - 1, Math.ceil((camera.pixelX + canvas.width) / BLOCK_SIZE));
    const lastY = Math.min(state.world.height - 1, Math.ceil((camera.pixelY + canvas.height) / BLOCK_SIZE));

    for (let y = firstY; y <= lastY; y++) {
        for (let x = firstX; x <= lastX; x++) {
            const type = state.world.grid[y][x];

            if (type === BlockType.Air) {
                continue;
            }

            ctx.fillStyle = COLORS[type];
            ctx.fillRect(
                x * BLOCK_SIZE - camera.pixelX,
                y * BLOCK_SIZE - camera.pixelY,
                BLOCK_SIZE,
                BLOCK_SIZE,
            );
        }
    }
}

function drawPlayer() {
    const width = state.rules.playerWidth * BLOCK_SIZE;
    const height = state.rules.playerHeight * BLOCK_SIZE;
    const screenX = player.x * BLOCK_SIZE - camera.pixelX;
    const screenY = player.y * BLOCK_SIZE - camera.pixelY;

    ctx.fillStyle = '#2f3640';
    ctx.fillRect(screenX, screenY + height * 0.35, width, height * 0.65);

    ctx.fillStyle = '#e8b07a';
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

function render() {
    drawWorld();
    drawPlayer();
    drawCursor();
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
    showStatus(`Kiválasztott blokk: ${BLOCK_NAMES[selectedType]}`);
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

        state.world.grid[data.y][data.x] = data.type;
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
    sendAction('place', { x, y, type: selectedType });
});

window.addEventListener('keydown', (event) => {
    const key = event.key.toLowerCase();

    pressedKeys.add(key);

    if (HOTBAR[key] !== undefined) {
        selectedType = HOTBAR[key];
        showSelection();
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
    render();

    if (timestamp - lastSyncedAt > POSITION_SYNC_INTERVAL) {
        syncPosition();
    }

    requestAnimationFrame(loop);
}

fetch('api/world.php')
    .then((response) => response.json())
    .then((data) => {
        state = data;
        player = {
            x: data.player.x,
            y: data.player.y,
            vx: 0,
            vy: 0,
            onGround: false,
        };

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
