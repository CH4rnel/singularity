import { statSync } from 'node:fs';
import { registerHooks } from 'node:module';

/**
 * Teaches `node --test` the `@/` alias Vite and tsconfig already resolve, so
 * frontend modules can be imported here exactly as the app imports them
 * instead of keeping a second, relative import style just for tests.
 */

const ROOT = new URL('../../resources/js/', import.meta.url);

const CANDIDATES = ['.ts', '.mts', '.js', '/index.ts', '/index.js', ''];

const isFile = (url) => {
    try {
        return statSync(url).isFile();
    } catch {
        return false;
    }
};

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (!specifier.startsWith('@/')) {
            return nextResolve(specifier, context);
        }

        const base = new URL(specifier.slice(2), ROOT);

        for (const suffix of CANDIDATES) {
            const candidate = new URL(base.href + suffix);

            if (isFile(candidate)) {
                return { url: candidate.href, shortCircuit: true };
            }
        }

        throw new Error(`Cannot resolve "${specifier}" under resources/js/`);
    },
});
