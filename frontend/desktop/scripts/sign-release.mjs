import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const desktop = fileURLToPath(new URL('..', import.meta.url));
const version = JSON.parse(readFileSync(path.join(desktop, 'package.json'), 'utf8')).version;
const directory = path.resolve(process.argv[2] || path.join(desktop, `dist/local-wallet-${version}`));
const publicKeyPath = path.resolve(process.argv[3] || path.join(homedir(), '.ssh/id_ed25519.pub'));
if (!publicKeyPath.endsWith('.pub')) throw new Error('Supply a public SSH key; signing uses ssh-agent.');
const publicKey = readFileSync(publicKeyPath, 'utf8').trim();
if (!/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-\S+) [A-Za-z0-9+/=]+(?: |$)/.test(publicKey)) throw new Error('Invalid public SSH key.');
const installers = readdirSync(directory).filter((name) => /\.(deb|AppImage)$/.test(name)).sort();
if (!installers.some((name) => name.endsWith('.deb')) || !installers.some((name) => name.endsWith('.AppImage'))) {
    throw new Error('Both Linux installers must exist before signing.');
}
for (const name of [...installers, 'SHA256SUMS.txt']) {
    if (existsSync(path.join(directory, name + '.sig'))) throw new Error(`A signature already exists: ${name}.sig`);
}
const sha256 = (file) => createHash('sha256').update(readFileSync(file)).digest('hex');
const run = (command, args, options = {}) => execFileSync(command, args, { cwd: desktop, ...options });
const namespace = 'cyberia-release';
const rendererSource = JSON.parse(readFileSync(path.join(desktop, 'wallet-dist/source.json'), 'utf8'));
if (rendererSource.version !== version) throw new Error('Rebuild the renderer for this version before signing.');
const metadata = {
    version,
    mode: 'bundled-local-wallet',
    sourceCommit: run('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(),
    sourceHasUncommittedChanges: run('git', ['status', '--porcelain'], { encoding: 'utf8' }).trim().length > 0,
    builtAt: new Date().toISOString(),
    rendererSource,
    rendererAssets: Object.fromEntries(readdirSync(path.join(desktop, 'wallet-dist/assets')).sort()
        .map((name) => [name, sha256(path.join(desktop, 'wallet-dist/assets', name))])),
};
writeFileSync(path.join(directory, 'RELEASE.json'), JSON.stringify(metadata, null, 2) + '\n');
writeFileSync(path.join(directory, 'SIGNING_KEY.pub'), publicKey + '\n');
writeFileSync(path.join(directory, 'allowed_signers'), `${namespace} namespaces="${namespace}" ${publicKey}\n`);
const previews = ['wallet-portfolio.png', 'wallet-locked.png'].filter((name) => existsSync(path.join(directory, name)));
const files = [...installers, ...previews, 'RELEASE.json', 'SIGNING_KEY.pub', 'allowed_signers'];
writeFileSync(path.join(directory, 'SHA256SUMS.txt'), files.map((name) => `${sha256(path.join(directory, name))}  ${name}\n`).join(''));

for (const name of [...installers, 'SHA256SUMS.txt']) {
    const file = path.join(directory, name);
    run('ssh-keygen', ['-Y', 'sign', '-f', publicKeyPath, '-n', namespace, file], { stdio: 'inherit' });
    run('ssh-keygen', ['-Y', 'verify', '-f', path.join(directory, 'allowed_signers'), '-I', namespace, '-n', namespace, '-s', file + '.sig'], {
        input: readFileSync(file), stdio: ['pipe', 'inherit', 'inherit'],
    });
}
console.log(run('ssh-keygen', ['-lf', publicKeyPath], { encoding: 'utf8' }).trim());
console.log(`Signed and verified release: ${directory}`);
