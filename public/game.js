const BLOCK_SIZE = 16;
const COLORS = {
    0: '#87CEEB',
    1: '#5f4c3d',
    2: '#42494a',
    3: '#4a913f',
};

const canvas = document.getElementById('game');
const ctx = canvas.getContext('2d');

function drawWorld(data) {
    for (let y = 0; y < data.height; y++) {
        for (let x = 0; x < data.width; x++) {
            const type = data.grid[y][x];
            ctx.fillStyle = COLORS[type];
            ctx.fillRect(x * BLOCK_SIZE, y * BLOCK_SIZE, BLOCK_SIZE, BLOCK_SIZE);

        }
    }
}

fetch('api/world.php')
    .then(response => response.json())
    .then(data => drawWorld(data));