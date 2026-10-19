/**
 * Layer 07: generative net art.
 *
 * Every piece is an algorithm and a seed. The algorithm is the work; the seed
 * is the edition, so there is no end to them and no two mints are the same
 * picture. Each one draws onto any 2D canvas at any size, deterministically —
 * the thumbnail, the full view and the PNG that is pinned for a mint are the
 * same image at three sizes.
 *
 * What opens a piece is a `Requirement` from `layers.ts`: a level, or a
 * particular find. One piece hides a word in its noise, and that word is a
 * secret of its own.
 */

import type { Requirement } from '@/lib/wallet/layers';

export type PieceId =
    | 'noise'
    | 'protocol7'
    | 'wires'
    | 'psyche'
    | 'accela'
    | 'knights'
    | 'room'
    | 'navi'
    | 'copland'
    | 'present'
    | 'club'
    | 'deus'
    | 'shadows';

export type Piece = { id: PieceId; title: string; requires: Requirement };

export const PIECES: readonly Piece[] = [
    { id: 'noise', title: 'Wired Noise', requires: { level: 1 } },
    { id: 'protocol7', title: 'Protocol 7', requires: { level: 1 } },
    { id: 'wires', title: 'Wires', requires: { level: 1 } },
    { id: 'psyche', title: 'Psyche', requires: { level: 2 } },
    { id: 'accela', title: 'Accela', requires: { secret: 'noise' } },
    { id: 'knights', title: 'Knights', requires: { level: 3 } },
    { id: 'room', title: 'Room', requires: { level: 3 } },
    { id: 'navi', title: 'NAVI', requires: { secret: 'navi' } },
    { id: 'copland', title: 'Copland', requires: { secret: 'theme' } },
    { id: 'present', title: 'Present Day', requires: { secret: 'present' } },
    { id: 'club', title: 'Cyberia Club', requires: { level: 4 } },
    { id: 'shadows', title: 'Shadows', requires: { secret: 'robots' } },
    { id: 'deus', title: 'Deus', requires: { level: 5 } },
];

/** A seeded generator: the same seed is the same picture, everywhere. */
export const mulberry32 = (seed: number): (() => number) => {
    let state = seed >>> 0;

    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
};

/** A fresh edition number, for "another one". */
export const randomSeed = (): number => 1 + Math.floor(Math.random() * 999_999);

const INK = '#e8e4dc';
const VOID = '#07080b';
const RED = '#d7263d';
const AQUA = '#72dccf';
const VIOLET = '#8b7bf7';
const AMBER = '#e8b44a';
const MONO = '"JetBrains Mono", ui-monospace, Menlo, Consolas, monospace';

type Ctx = CanvasRenderingContext2D;
type Rng = () => number;

const between = (rng: Rng, low: number, high: number): number =>
    low + rng() * (high - low);

const pick = <T>(rng: Rng, items: readonly T[]): T =>
    items[Math.floor(rng() * items.length)];

const fill = (ctx: Ctx, size: number, color: string): void => {
    ctx.fillStyle = color;
    ctx.fillRect(0, 0, size, size);
};

/** Fine horizontal lines over everything, the way a tube draws. */
const scanlines = (ctx: Ctx, size: number, alpha = 0.18): void => {
    ctx.fillStyle = `rgba(0, 0, 0, ${alpha})`;
    const step = Math.max(2, Math.round(size / 256));

    for (let y = 0; y < size; y += step * 2) {
        ctx.fillRect(0, y, size, step);
    }
};

/** Monochrome grain at a given strength, through pixel data. */
const grain = (ctx: Ctx, size: number, rng: Rng, strength: number): void => {
    const image = ctx.getImageData(0, 0, size, size);
    const data = image.data;

    for (let index = 0; index < data.length; index += 4) {
        const shift = (rng() - 0.5) * strength;
        data[index] += shift;
        data[index + 1] += shift;
        data[index + 2] += shift;
    }

    ctx.putImageData(image, 0, 0);
};

/* ----------------------------------------------------------------- works - */

/**
 * Static, torn by rows that slipped sideways. The word is drawn into the
 * noise at a low contrast in every edition, somewhere else each time: it is
 * there for whoever looks long enough.
 */
const noise = (ctx: Ctx, size: number, rng: Rng): void => {
    const image = ctx.createImageData(size, size);
    const data = image.data;
    const block = Math.max(1, Math.round(size / 340));

    for (let y = 0; y < size; y += block) {
        for (let x = 0; x < size; x += block) {
            const value = 18 + rng() * 70;

            for (let dy = 0; dy < block && y + dy < size; dy++) {
                for (let dx = 0; dx < block && x + dx < size; dx++) {
                    const index = ((y + dy) * size + (x + dx)) * 4;
                    data[index] = value;
                    data[index + 1] = value;
                    data[index + 2] = value * 1.06;
                    data[index + 3] = 255;
                }
            }
        }
    }

    ctx.putImageData(image, 0, 0);

    ctx.save();
    ctx.globalAlpha = 0.16;
    ctx.fillStyle = '#9aa3ad';
    ctx.font = `600 ${Math.round(size * 0.09)}px ${MONO}`;
    ctx.textBaseline = 'middle';
    ctx.fillText(
        'accela',
        between(rng, size * 0.08, size * 0.5),
        between(rng, size * 0.15, size * 0.85),
    );
    ctx.restore();

    for (let tear = 0; tear < 9; tear++) {
        const y = Math.floor(rng() * size);
        const height = Math.floor(between(rng, size * 0.004, size * 0.03));
        const shift = Math.floor((rng() - 0.5) * size * 0.2);
        const row = ctx.getImageData(0, y, size, Math.max(1, height));
        ctx.putImageData(row, shift, y);
    }

    ctx.fillStyle = 'rgba(114, 220, 207, 0.06)';
    ctx.fillRect(0, between(rng, 0, size), size, size * 0.12);
    scanlines(ctx, size, 0.22);
};

/** Rings with pieces missing, ticks, and one red arc that matters. */
const protocol7 = (ctx: Ctx, size: number, rng: Rng): void => {
    fill(ctx, size, VOID);
    const centre = size / 2;
    ctx.lineCap = 'butt';

    for (let ring = 0; ring < 34; ring++) {
        const radius = size * between(rng, 0.05, 0.47);
        const start = rng() * Math.PI * 2;
        const sweep = between(rng, 0.2, Math.PI * 1.7);
        ctx.strokeStyle = rng() < 0.08 ? RED : rng() < 0.4 ? AQUA : INK;
        ctx.globalAlpha = between(rng, 0.35, 1);
        ctx.lineWidth = size * between(rng, 0.001, 0.012);
        ctx.beginPath();
        ctx.arc(centre, centre, radius, start, start + sweep);
        ctx.stroke();
    }

    ctx.globalAlpha = 0.8;
    ctx.strokeStyle = INK;
    ctx.lineWidth = size * 0.0015;

    for (let tick = 0; tick < 7 * 12; tick++) {
        const angle = (tick / (7 * 12)) * Math.PI * 2;
        const inner = size * (tick % 12 === 0 ? 0.36 : 0.4);
        ctx.beginPath();
        ctx.moveTo(
            centre + Math.cos(angle) * inner,
            centre + Math.sin(angle) * inner,
        );
        ctx.lineTo(
            centre + Math.cos(angle) * size * 0.42,
            centre + Math.sin(angle) * size * 0.42,
        );
        ctx.stroke();
    }

    ctx.globalAlpha = 1;
    ctx.fillStyle = INK;
    ctx.font = `500 ${Math.round(size * 0.06)}px ${MONO}`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('7', centre, centre);
    ctx.textAlign = 'start';
};

/** Poles and the wires between them, against a sky the colour of paper. */
const wires = (ctx: Ctx, size: number, rng: Rng): void => {
    const sky = ctx.createLinearGradient(0, 0, 0, size);
    sky.addColorStop(0, '#f1ede4');
    sky.addColorStop(0.75, '#d9d2c4');
    sky.addColorStop(1, rng() < 0.5 ? '#c96a5a' : '#b9b0a0');
    ctx.fillStyle = sky;
    ctx.fillRect(0, 0, size, size);

    ctx.fillStyle = 'rgba(215, 38, 61, 0.85)';
    ctx.beginPath();
    ctx.arc(
        between(rng, size * 0.15, size * 0.85),
        size * between(rng, 0.15, 0.4),
        size * 0.05,
        0,
        Math.PI * 2,
    );
    ctx.fill();

    const poles: { x: number; top: number; arms: number[] }[] = [];
    let x = -size * between(rng, 0, 0.1);

    while (x < size * 1.15) {
        const top = size * between(rng, 0.12, 0.42);
        poles.push({ x, top, arms: [top + size * 0.03, top + size * 0.075] });
        x += size * between(rng, 0.22, 0.45);
    }

    ctx.strokeStyle = '#111';

    for (const pole of poles) {
        ctx.lineWidth = size * 0.012;
        ctx.beginPath();
        ctx.moveTo(pole.x, size);
        ctx.lineTo(pole.x, pole.top);
        ctx.stroke();

        ctx.lineWidth = size * 0.006;

        for (const arm of pole.arms) {
            ctx.beginPath();
            ctx.moveTo(pole.x - size * 0.07, arm);
            ctx.lineTo(pole.x + size * 0.07, arm);
            ctx.stroke();
        }
    }

    ctx.lineWidth = size * 0.0018;

    for (let index = 0; index < poles.length - 1; index++) {
        const left = poles[index];
        const right = poles[index + 1];

        for (const [arm, offset] of [
            [0, -0.06],
            [0, 0.06],
            [1, -0.05],
            [1, 0],
            [1, 0.05],
        ] as const) {
            const startX = left.x + size * offset;
            const startY = left.arms[arm];
            const endX = right.x + size * offset;
            const endY = right.arms[arm];
            const sag = size * between(rng, 0.03, 0.09);
            ctx.beginPath();
            ctx.moveTo(startX, startY);
            ctx.quadraticCurveTo(
                (startX + endX) / 2,
                (startY + endY) / 2 + sag * 2,
                endX,
                endY,
            );
            ctx.stroke();
        }
    }

    grain(ctx, size, rng, 18);
};

/** Two sets of rings, a hair apart. The pattern is in neither of them. */
const psyche = (ctx: Ctx, size: number, rng: Rng): void => {
    fill(ctx, size, VOID);
    const gap = size * between(rng, 0.008, 0.014);
    const sets = [
        { x: size * 0.5, y: size * 0.5, color: INK },
        {
            x: size * (0.5 + between(rng, -0.08, 0.08)),
            y: size * (0.5 + between(rng, -0.08, 0.08)),
            color: rng() < 0.5 ? RED : VIOLET,
        },
    ];

    ctx.lineWidth = gap * 0.45;

    for (const set of sets) {
        ctx.strokeStyle = set.color;

        for (let radius = gap; radius < size * 0.8; radius += gap) {
            ctx.beginPath();
            ctx.arc(set.x, set.y, radius, 0, Math.PI * 2);
            ctx.stroke();
        }
    }
};

/** A burst from the centre, printed three times out of register. */
const accela = (ctx: Ctx, size: number, rng: Rng): void => {
    fill(ctx, size, VOID);
    const rays = Array.from({ length: 260 }, () => ({
        angle: rng() * Math.PI * 2,
        from: size * between(rng, 0.02, 0.12),
        to: size * between(rng, 0.15, 0.7) ** 1,
        width: size * between(rng, 0.001, 0.006),
    }));

    ctx.globalCompositeOperation = 'lighter';

    for (const [color, dx] of [
        ['rgba(255, 40, 70, 0.7)', -size * 0.008],
        ['rgba(40, 255, 200, 0.6)', size * 0.008],
        ['rgba(120, 120, 255, 0.6)', 0],
    ] as const) {
        ctx.strokeStyle = color;

        for (const ray of rays) {
            ctx.lineWidth = ray.width;
            ctx.beginPath();
            ctx.moveTo(
                size / 2 + dx + Math.cos(ray.angle) * ray.from,
                size / 2 + Math.sin(ray.angle) * ray.from,
            );
            ctx.lineTo(
                size / 2 + dx + Math.cos(ray.angle) * ray.to,
                size / 2 + Math.sin(ray.angle) * ray.to,
            );
            ctx.stroke();
        }
    }

    ctx.globalCompositeOperation = 'source-over';
    scanlines(ctx, size, 0.25);
};

/** An alphabet nobody taught: strokes between the points of a 3×3 lattice. */
const knights = (ctx: Ctx, size: number, rng: Rng): void => {
    fill(ctx, size, '#0d0e12');
    const cells = 12;
    const cell = size / cells;
    ctx.lineCap = 'square';
    ctx.lineWidth = cell * 0.08;

    for (let row = 0; row < cells; row++) {
        for (let column = 0; column < cells; column++) {
            const point = (): [number, number] => [
                column * cell + cell * (0.22 + Math.floor(rng() * 3) * 0.28),
                row * cell + cell * (0.22 + Math.floor(rng() * 3) * 0.28),
            ];

            ctx.strokeStyle = rng() < 0.06 ? RED : INK;
            ctx.globalAlpha = between(rng, 0.55, 1);
            const strokes = 2 + Math.floor(rng() * 3);

            for (let stroke = 0; stroke < strokes; stroke++) {
                const [ax, ay] = point();
                const [bx, by] = point();
                ctx.beginPath();
                ctx.moveTo(ax, ay);
                ctx.lineTo(bx, by);
                ctx.stroke();
            }
        }
    }

    ctx.globalAlpha = 1;
};

/** A room made of cables, and the screens they end in. */
const room = (ctx: Ctx, size: number, rng: Rng): void => {
    const floor = ctx.createLinearGradient(0, 0, 0, size);
    floor.addColorStop(0, '#0b0d12');
    floor.addColorStop(1, '#1a1c22');
    ctx.fillStyle = floor;
    ctx.fillRect(0, 0, size, size);

    for (let index = 0; index < 5; index++) {
        const width = size * between(rng, 0.12, 0.22);
        const height = width * 0.75;
        const x = between(rng, 0, size - width);
        const y = between(rng, size * 0.05, size * 0.5);
        ctx.shadowColor = AQUA;
        ctx.shadowBlur = size * 0.04;
        ctx.fillStyle = 'rgba(114, 220, 207, 0.22)';
        ctx.fillRect(x, y, width, height);
        ctx.shadowBlur = 0;
        ctx.strokeStyle = '#2a2e38';
        ctx.lineWidth = size * 0.006;
        ctx.strokeRect(x, y, width, height);
    }

    for (let index = 0; index < 70; index++) {
        ctx.strokeStyle = pick(rng, [
            '#050506',
            '#15171c',
            '#23262e',
            '#2f333d',
            '#3c1118',
        ]);
        ctx.lineWidth = size * between(rng, 0.003, 0.014);
        ctx.beginPath();
        ctx.moveTo(between(rng, -0.1, 1.1) * size, size * 1.05);
        ctx.bezierCurveTo(
            between(rng, -0.2, 1.2) * size,
            between(rng, 0.2, 1) * size,
            between(rng, -0.2, 1.2) * size,
            between(rng, 0, 0.8) * size,
            between(rng, -0.1, 1.1) * size,
            between(rng, -0.05, 0.6) * size,
        );
        ctx.stroke();
    }
};

/** A desktop from an operating system that should not exist. */
const navi = (ctx: Ctx, size: number, rng: Rng): void => {
    fill(ctx, size, '#2f6f73');
    const unit = size / 100;

    const panel = (
        x: number,
        y: number,
        width: number,
        height: number,
        title: string,
    ): void => {
        ctx.fillStyle = '#c3c7cb';
        ctx.fillRect(x, y, width, height);
        ctx.fillStyle = '#fff';
        ctx.fillRect(x, y, width, unit * 0.4);
        ctx.fillRect(x, y, unit * 0.4, height);
        ctx.fillStyle = '#5a5e64';
        ctx.fillRect(x, y + height - unit * 0.4, width, unit * 0.4);
        ctx.fillRect(x + width - unit * 0.4, y, unit * 0.4, height);
        ctx.fillStyle = rng() < 0.3 ? '#7a1020' : '#10247a';
        ctx.fillRect(x + unit, y + unit, width - unit * 2, unit * 4);
        ctx.fillStyle = '#fff';
        ctx.font = `700 ${Math.round(unit * 2.6)}px ${MONO}`;
        ctx.textBaseline = 'middle';
        ctx.fillText(title, x + unit * 2, y + unit * 3);

        for (
            let line = 0;
            line < Math.floor((height - unit * 8) / (unit * 3));
            line++
        ) {
            ctx.fillStyle = rng() < 0.15 ? RED : '#26292e';
            ctx.fillRect(
                x + unit * 2,
                y + unit * 7 + line * unit * 3,
                (width - unit * 4) * between(rng, 0.2, 1),
                unit * 1.2,
            );
        }
    };

    const titles = [
        'NAVI',
        'PSYCHE.SYS',
        'KNIGHTS',
        'LAYER:07',
        'WIRED.EXE',
        'PROTOCOL 7',
        'DEUS',
    ];

    for (let index = 0; index < 6; index++) {
        const width = between(rng, 30, 60) * unit;
        const height = between(rng, 20, 45) * unit;
        panel(
            between(rng, 2, 98 - width / unit) * unit,
            between(rng, 2, 92 - height / unit) * unit,
            width,
            height,
            pick(rng, titles),
        );
    }

    panel(18 * unit, 30 * unit, 64 * unit, 38 * unit, 'NAVI');
    ctx.fillStyle = '#26292e';
    ctx.fillRect(22 * unit, 58 * unit, 56 * unit, 4 * unit);
    ctx.fillStyle = AQUA;
    ctx.fillRect(
        22 * unit,
        58 * unit,
        56 * unit * between(rng, 0.1, 0.95),
        4 * unit,
    );

    const cx = between(rng, 30, 80) * unit;
    const cy = between(rng, 30, 80) * unit;
    ctx.fillStyle = '#fff';
    ctx.strokeStyle = '#000';
    ctx.lineWidth = unit * 0.35;
    ctx.beginPath();
    ctx.moveTo(cx, cy);
    ctx.lineTo(cx, cy + unit * 6);
    ctx.lineTo(cx + unit * 1.6, cy + unit * 4.6);
    ctx.lineTo(cx + unit * 3.6, cy + unit * 4.4);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
};

/** A mandala typed out of characters, eightfold. */
const copland = (ctx: Ctx, size: number, rng: Rng): void => {
    fill(ctx, size, VOID);
    const cells = 48;
    const cell = size / cells;
    const glyphs = ' .:-=+*#%@01ｦｱｳｴｵｶｷ'.split('');
    const folds = pick(rng, [6, 8, 12]);
    const frequency = between(rng, 0.25, 0.6);
    const phase = rng() * 10;
    ctx.font = `500 ${Math.round(cell * 0.9)}px ${MONO}`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    for (let row = 0; row < cells; row++) {
        for (let column = 0; column < cells; column++) {
            const dx = column - cells / 2 + 0.5;
            const dy = row - cells / 2 + 0.5;
            const radius = Math.hypot(dx, dy);
            const angle = Math.atan2(dy, dx);
            const value =
                (Math.sin(radius * frequency + phase) +
                    Math.cos(angle * folds + radius * 0.15)) /
                2;

            if (radius > cells * 0.49) {
                continue;
            }

            const index = Math.floor(((value + 1) / 2) * (glyphs.length - 1));
            ctx.fillStyle = value > 0.8 ? RED : value > 0.2 ? AQUA : '#3c5a5a';
            ctx.fillText(
                glyphs[index],
                column * cell + cell / 2,
                row * cell + cell / 2,
            );
        }
    }

    ctx.textAlign = 'start';
};

/** Present day. Present time. And the laughter after it. */
const present = (ctx: Ctx, size: number, rng: Rng): void => {
    fill(ctx, size, '#040504');
    const line = size / 32;
    ctx.font = `500 ${Math.round(line * 0.8)}px ${MONO}`;
    ctx.textBaseline = 'top';

    for (let row = 0; row < 32; row++) {
        const text =
            rng() < 0.5
                ? 'HAHAHAHAHAHAHAHAHAHA'
                : rng() < 0.5
                  ? 'PRESENT DAY'
                  : 'PRESENT TIME';
        ctx.fillStyle = `rgba(190, 255, 200, ${between(rng, 0.04, 0.28)})`;
        ctx.fillText(text.repeat(3), -between(rng, 0, size * 0.5), row * line);
    }

    ctx.fillStyle = '#e9ffe9';
    ctx.font = `700 ${Math.round(size * 0.075)}px ${MONO}`;
    ctx.fillText('PRESENT DAY', size * 0.08, size * 0.38);
    ctx.fillText('PRESENT TIME', size * 0.08, size * 0.48);
    ctx.fillStyle = RED;
    ctx.fillRect(
        size * 0.08,
        size * 0.6,
        size * between(rng, 0.2, 0.8),
        size * 0.01,
    );
    scanlines(ctx, size, 0.3);
};

/** A dance floor running to the horizon, and the lasers over it. */
const club = (ctx: Ctx, size: number, rng: Rng): void => {
    fill(ctx, size, '#06040a');
    const horizon = size * between(rng, 0.32, 0.45);
    const vanish = size * between(rng, 0.35, 0.65);

    for (let laser = 0; laser < 16; laser++) {
        ctx.strokeStyle = pick(rng, [RED, VIOLET, AQUA, AMBER]);
        ctx.globalAlpha = between(rng, 0.2, 0.7);
        ctx.lineWidth = size * 0.002;
        ctx.beginPath();
        ctx.moveTo(between(rng, 0, size), 0);
        ctx.lineTo(between(rng, -size * 0.5, size * 1.5), size);
        ctx.stroke();
    }

    ctx.globalAlpha = 1;
    const rows = 14;
    const columns = 16;

    for (let row = 0; row < rows; row++) {
        const near = (row + 1) / rows;
        const far = row / rows;
        const yFar = horizon + (size - horizon) * far ** 2;
        const yNear = horizon + (size - horizon) * near ** 2;

        for (let column = -columns; column < columns; column++) {
            const corner = (y: number, c: number): [number, number] => {
                const depth = (y - horizon) / (size - horizon);

                return [vanish + (c / columns) * size * 1.6 * depth, y];
            };

            const lit = rng() < 0.18;
            ctx.fillStyle = lit
                ? pick(rng, [RED, VIOLET, AQUA, AMBER])
                : '#120e18';
            ctx.globalAlpha = lit ? between(rng, 0.4, 0.9) : 1;
            ctx.beginPath();
            ctx.moveTo(...corner(yFar, column));
            ctx.lineTo(...corner(yFar, column + 1));
            ctx.lineTo(...corner(yNear, column + 1));
            ctx.lineTo(...corner(yNear, column));
            ctx.closePath();
            ctx.fill();
        }
    }

    ctx.globalAlpha = 1;
    const fog = ctx.createLinearGradient(
        0,
        horizon - size * 0.05,
        0,
        horizon + size * 0.2,
    );
    fog.addColorStop(0, 'rgba(6, 4, 10, 1)');
    fog.addColorStop(1, 'rgba(6, 4, 10, 0)');
    ctx.fillStyle = fog;
    ctx.fillRect(0, horizon - size * 0.05, size, size * 0.25);
    ctx.fillStyle = INK;
    ctx.font = `600 ${Math.round(size * 0.035)}px ${MONO}`;
    ctx.fillText('CYBERIA', size * 0.06, size * 0.08);
};

/** Shadows on a bright street, and the red that lives inside them. */
const shadows = (ctx: Ctx, size: number, rng: Rng): void => {
    fill(ctx, size, '#f2efe8');

    for (let index = 0; index < 5; index++) {
        const points = 9;
        const cx = between(rng, 0.1, 0.9) * size;
        const cy = between(rng, 0.2, 1) * size;
        const radius = between(rng, 0.12, 0.32) * size;
        const path = new Path2D();

        for (let point = 0; point <= points; point++) {
            const angle = (point / points) * Math.PI * 2;
            const reach = radius * between(rng, 0.6, 1.3);
            const x = cx + Math.cos(angle) * reach;
            const y = cy + Math.sin(angle) * reach * 1.6;

            if (point === 0) {
                path.moveTo(x, y);
            } else {
                path.lineTo(x, y);
            }
        }

        path.closePath();
        ctx.fillStyle = '#16131a';
        ctx.fill(path);
        ctx.save();
        ctx.clip(path);

        for (let spot = 0; spot < 26; spot++) {
            ctx.fillStyle = pick(rng, ['#c0123a', '#e0245e', '#8f0f2f']);
            ctx.beginPath();
            ctx.arc(
                cx + (rng() - 0.5) * radius * 2.4,
                cy + (rng() - 0.5) * radius * 3.4,
                size * between(rng, 0.004, 0.02),
                0,
                Math.PI * 2,
            );
            ctx.fill();
        }

        ctx.restore();
    }

    grain(ctx, size, rng, 14);
};

/** An eye over everything, and everything radiating from it. */
const deus = (ctx: Ctx, size: number, rng: Rng): void => {
    fill(ctx, size, VOID);
    const cx = size / 2;
    const cy = size * between(rng, 0.42, 0.55);

    ctx.lineWidth = size * 0.0012;

    for (let ray = 0; ray < 360; ray++) {
        const angle = (ray / 360) * Math.PI * 2;
        ctx.strokeStyle = ray % 23 === 0 ? RED : INK;
        ctx.globalAlpha = between(rng, 0.08, 0.5);
        ctx.beginPath();
        ctx.moveTo(
            cx + Math.cos(angle) * size * 0.2,
            cy + Math.sin(angle) * size * 0.12,
        );
        ctx.lineTo(cx + Math.cos(angle) * size, cy + Math.sin(angle) * size);
        ctx.stroke();
    }

    ctx.globalAlpha = 1;
    const width = size * 0.34;
    const height = size * between(rng, 0.1, 0.16);
    ctx.fillStyle = '#ece7dd';
    ctx.beginPath();
    ctx.moveTo(cx - width, cy);
    ctx.quadraticCurveTo(cx, cy - height * 2, cx + width, cy);
    ctx.quadraticCurveTo(cx, cy + height * 2, cx - width, cy);
    ctx.fill();

    for (const [radius, color] of [
        [height * 0.95, RED],
        [height * 0.6, '#3a0710'],
        [height * 0.28, '#000'],
    ] as const) {
        ctx.fillStyle = color;
        ctx.beginPath();
        ctx.arc(cx + (rng() - 0.5) * size * 0.04, cy, radius, 0, Math.PI * 2);
        ctx.fill();
    }

    ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
    ctx.beginPath();
    ctx.arc(
        cx + height * 0.3,
        cy - height * 0.3,
        height * 0.12,
        0,
        Math.PI * 2,
    );
    ctx.fill();
    scanlines(ctx, size, 0.2);
};

const WORKS: Record<PieceId, (ctx: Ctx, size: number, rng: Rng) => void> = {
    noise,
    protocol7,
    wires,
    psyche,
    accela,
    knights,
    room,
    navi,
    copland,
    present,
    club,
    deus,
    shadows,
};

/** Draw one edition of one piece onto a square canvas. */
export const drawPiece = (
    ctx: CanvasRenderingContext2D,
    piece: PieceId,
    seed: number,
    size: number,
): void => {
    ctx.save();
    ctx.clearRect(0, 0, size, size);
    WORKS[piece](ctx, size, mulberry32(seed * 7919 + piece.length));
    ctx.restore();
};

/**
 * What a closed piece shows: plain static, with nothing hidden in it — the
 * word in `noise` belongs to whoever looks into that piece, not to every
 * locked tile in the grid.
 */
export const drawLocked = (
    ctx: CanvasRenderingContext2D,
    size: number,
): void => {
    fill(ctx, size, '#1b1d22');
    grain(ctx, size, mulberry32(13), 60);
    scanlines(ctx, size, 0.3);
};

/** One edition as a PNG, at the size it is minted at. */
export const renderPiecePng = (
    piece: PieceId,
    seed: number,
    size = 1024,
): Promise<Blob> => {
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    if (ctx === null) {
        return Promise.reject(new Error('This browser cannot draw.'));
    }

    drawPiece(ctx, piece, seed, size);

    return new Promise((resolve, reject) =>
        canvas.toBlob(
            (blob) =>
                blob
                    ? resolve(blob)
                    : reject(new Error('This browser cannot draw.')),
            'image/png',
        ),
    );
};
