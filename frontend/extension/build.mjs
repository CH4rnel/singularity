/**
 * Builds the unpacked extension into `dist/`, and with `--zip` the single file
 * that /download hands out.
 *
 * Everything the browser loads has to be a plain file next to the manifest, so
 * the five entry points are bundled with esbuild and the static files are
 * copied verbatim. The zip is written here rather than shelled out to `zip`
 * because the release workflow runs on three operating systems and only one of
 * them is guaranteed to have that binary.
 */
import { build } from 'esbuild';
import { deflateRawSync, crc32 } from 'node:zlib';
import { cp, mkdir, readdir, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const dist = join(root, 'dist');
const pkg = JSON.parse(await readFile(join(root, 'package.json'), 'utf8'));

/** The extension's own name, so the release asset never carries a version. */
const ZIP_NAME = 'Cyberia-extension.zip';

const ENTRIES = {
    background: 'src/background/index.js',
    content: 'src/content/bridge.js',
    inpage: 'src/inpage/provider.js',
    popup: 'src/popup/popup.js',
    onboarding: 'src/onboarding/onboarding.js',
};

const STATIC = [
    ['src/popup/popup.html', 'popup.html'],
    ['src/popup/popup.css', 'popup.css'],
    ['src/onboarding/onboarding.html', 'onboarding.html'],
    ['icons', 'icons'],
];

await rm(dist, { recursive: true, force: true });
await mkdir(dist, { recursive: true });

await build({
    entryPoints: Object.fromEntries(
        Object.entries(ENTRIES).map(([name, file]) => [name, join(root, file)]),
    ),
    outdir: dist,
    bundle: true,
    format: 'esm',
    target: 'chrome111',
    platform: 'browser',
    minify: true,
    // A wallet people are asked to trust has to be auditable from the file they
    // installed: the maps make the bundled source readable in devtools.
    sourcemap: true,
    legalComments: 'linked',
    logLevel: 'info',
});

for (const [from, to] of STATIC) {
    await cp(join(root, from), join(dist, to), { recursive: true });
}

// The manifest version is the package version, which CI writes from the tag.
const manifest = JSON.parse(await readFile(join(root, 'manifest.json'), 'utf8'));
manifest.version = pkg.version;
await writeFile(join(dist, 'manifest.json'), `${JSON.stringify(manifest, null, 4)}\n`);

console.log(`built ${manifest.name} ${manifest.version} into dist/`);

if (process.argv.includes('--zip')) {
    const zip = join(root, ZIP_NAME);
    await rm(zip, { force: true });
    await writeFile(zip, await zipDirectory(dist));
    const { size } = await stat(zip);
    console.log(`packed ${ZIP_NAME} · ${(size / 1024).toFixed(0)} KB`);
}

/* ------------------------------------------------------------------- zip --- */

/** Every file under `dir`, deepest paths included, in a stable order. */
async function walk(dir) {
    const found = [];

    for (const entry of await readdir(dir, { withFileTypes: true })) {
        const path = join(dir, entry.name);
        found.push(...(entry.isDirectory() ? await walk(path) : [path]));
    }

    return found.sort();
}

/**
 * A ZIP with no extra fields and no timestamps, so two builds of the same
 * source produce the same bytes and a published checksum means something.
 */
async function zipDirectory(dir) {
    const files = await walk(dir);
    const chunks = [];
    const central = [];
    let offset = 0;

    for (const path of files) {
        const name = relative(dir, path).split('\\').join('/');
        const body = await readFile(path);
        const deflated = deflateRawSync(body, { level: 9 });
        // Storing is smaller than deflating for anything already compressed.
        const stored = deflated.length >= body.length;
        const data = stored ? body : deflated;
        const entry = {
            name: Buffer.from(name, 'utf8'),
            method: stored ? 0 : 8,
            crc: crc32(body),
            size: body.length,
            packed: data.length,
            offset,
        };

        const local = Buffer.alloc(30);
        local.writeUInt32LE(0x04034b50, 0);
        local.writeUInt16LE(20, 4); // version needed
        local.writeUInt16LE(0, 6); // flags
        local.writeUInt16LE(entry.method, 8);
        local.writeUInt32LE(0, 10); // time + date, fixed for reproducibility
        local.writeUInt32LE(entry.crc, 14);
        local.writeUInt32LE(entry.packed, 18);
        local.writeUInt32LE(entry.size, 22);
        local.writeUInt16LE(entry.name.length, 26);
        local.writeUInt16LE(0, 28); // extra field length

        chunks.push(local, entry.name, data);
        offset += local.length + entry.name.length + data.length;
        central.push(entry);
    }

    const directory = [];

    for (const entry of central) {
        const header = Buffer.alloc(46);
        header.writeUInt32LE(0x02014b50, 0);
        header.writeUInt16LE(20, 4); // version made by
        header.writeUInt16LE(20, 6); // version needed
        header.writeUInt16LE(0, 8);
        header.writeUInt16LE(entry.method, 10);
        header.writeUInt32LE(0, 12);
        header.writeUInt32LE(entry.crc, 16);
        header.writeUInt32LE(entry.packed, 20);
        header.writeUInt32LE(entry.size, 24);
        header.writeUInt16LE(entry.name.length, 28);
        header.writeUInt32LE(entry.offset, 42); // where its local header starts
        directory.push(header, entry.name);
    }

    const directorySize = directory.reduce((sum, part) => sum + part.length, 0);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(central.length, 8);
    end.writeUInt16LE(central.length, 10);
    end.writeUInt32LE(directorySize, 12);
    end.writeUInt32LE(offset, 16);

    return Buffer.concat([...chunks, ...directory, end]);
}
