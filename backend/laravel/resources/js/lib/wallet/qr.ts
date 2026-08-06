import qrcode from 'qrcode-generator';

/**
 * QR codes for receive addresses.
 *
 * A wrong QR sends money to nobody, so the encoding is left to a library that
 * has been read by more eyes than this file ever will be; what lives here is
 * only the conversion into a module grid the page can draw as one SVG path.
 *
 * Error correction is M: high enough to survive a scuffed phone screen, low
 * enough that a 95-character Monero address still fits in a grid that reads at
 * thumbnail size.
 */

export type QrMatrix = {
    /** Modules per side, excluding the quiet zone. */
    size: number;
    /** Row-major dark/light modules, `size × size` long. */
    modules: boolean[];
};

export const qrMatrix = (text: string): QrMatrix => {
    // Type 0 lets the library pick the smallest version that fits the data.
    const code = qrcode(0, 'M');

    code.addData(text);
    code.make();

    const size = code.getModuleCount();
    const modules: boolean[] = [];

    for (let row = 0; row < size; row++) {
        for (let column = 0; column < size; column++) {
            modules.push(code.isDark(row, column));
        }
    }

    return { size, modules };
};

/**
 * The dark modules as a single SVG path, in a viewBox that already includes
 * the four-module quiet zone the spec requires around the symbol.
 */
export const qrSvgPath = (matrix: QrMatrix, quietZone = 4): string => {
    const parts: string[] = [];

    for (let row = 0; row < matrix.size; row++) {
        for (let column = 0; column < matrix.size; column++) {
            if (matrix.modules[row * matrix.size + column]) {
                parts.push(`M${column + quietZone} ${row + quietZone}h1v1h-1z`);
            }
        }
    }

    return parts.join('');
};
