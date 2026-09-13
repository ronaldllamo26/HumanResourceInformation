import fs from 'node:fs';
import path from 'node:path';

/*
 * Names used in a component but never brought into scope.
 *
 * This is the one class of bug the PHP suite cannot see: a feature test
 * asserts the server's response, and the failure is in the browser. Vite does
 * not warn either — an undefined name compiles fine and throws at render.
 *
 * Two mistakes have now shipped this way. A lucide icon used without being
 * imported blanked one screen; a `roles: [ROLE.ADMIN]` written into
 * navigation.js, where no such constant exists, blanked *every* screen,
 * because every page imports that config and a ReferenceError at module load
 * takes the whole bundle down with it.
 */

/*
 * Lower-case globals a browser or the language provides. Listed rather than
 * guessed at, for the same reason the capitalised set above is: a name that is
 * not here and not declared really is undefined at runtime.
 */
const LOWERCASE_GLOBALS = new Set([
    'console',
    'window',
    'document',
    'navigator',
    'location',
    'history',
    'localStorage',
    'sessionStorage',
    'fetch',
    'alert',
    'confirm',
    'prompt',
    'setTimeout',
    'clearTimeout',
    'setInterval',
    'clearInterval',
    'requestAnimationFrame',
    'cancelAnimationFrame',
    'queueMicrotask',
    'structuredClone',
    'parseInt',
    'parseFloat',
    'isNaN',
    'isFinite',
    'encodeURIComponent',
    'decodeURIComponent',
    'encodeURI',
    'decodeURI',
    'require',
    'import',
    'typeof',
    'return',
    'await',
    'if',
    'for',
    'while',
    'switch',
    'catch',
    'function',
    'super',
    'this',
    // Ziggy's global route helper, injected by the Laravel plugin.
    'route',
    'async',
]);

/**
 * The file with its comments and string literals blanked out.
 *
 * Offsets are preserved — every removed character becomes a space — so a match
 * found here still points at the right line in the original. Without this the
 * scan reads prose as code: `{count} day(s) of leave` is a pluralised noun in
 * JSX text and looks exactly like a call, and a sentence inside a block comment
 * can name anything at all.
 */
function stripNonCode(source) {
    const blank = (text) => text.replace(/[^\n]/g, ' ');

    return source
        .replace(/\/\*[\s\S]*?\*\//g, blank)
        .replace(/(^|[^:])\/\/[^\n]*/g, (match, lead) => lead + blank(match.slice(lead.length)))
        .replace(/`(?:[^`\\]|\\.)*`/g, blank)
        .replace(/'(?:[^'\\\n]|\\.)*'/g, blank)
        .replace(/"(?:[^"\\\n]|\\.)*"/g, blank);
}

const files = [];
(function walk(dir) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(full);
        else if (/\.(jsx?|mjs)$/.test(entry.name)) files.push(full);
    }
})('resources/js');

let bad = 0;

for (const file of files) {
    const raw = fs.readFileSync(file, 'utf8');

    // Declarations are read from the raw file so a name defined inside a
    // template literal still counts; *uses* are read from the stripped copy,
    // so prose never does.
    const source = stripNonCode(raw);

    /*
     * Globals a browser provides. Listed rather than guessed at, so the
     * check stays a check: a name that is not here and not imported really
     * is undefined at runtime.
     */
    const known = new Set([
        'Fragment',
        // Standard library
        'Math',
        'Object',
        'Array',
        'JSON',
        'Date',
        'Number',
        'String',
        'Boolean',
        'Intl',
        'Promise',
        'Error',
        'RegExp',
        'Set',
        'Map',
        'Symbol',
        'BigInt',
        'Proxy',
        'Reflect',
        'WeakMap',
        'WeakSet',
        // Browser — URL.createObjectURL is used for the photo preview
        'URL',
        'FormData',
        'FileReader',
        'Blob',
        'File',
        'Image',
        'Notification',
        'AbortController',
        'IntersectionObserver',
        'ResizeObserver',
        'MutationObserver',
        'CustomEvent',
        'Event',
        'URLSearchParams',
        'Response',
        'Request',
        'Headers',
    ]);

    // Every import, named and default, across as many lines as it takes.
    for (const match of raw.matchAll(/import\s+([\s\S]*?)\s+from\s+['"][^'"]+['"]/g)) {
        const clause = match[1];

        const named = clause.match(/\{([\s\S]*?)\}/);
        if (named) {
            named[1]
                .split(',')
                .map((name) =>
                    name
                        .trim()
                        .split(/\s+as\s+/)
                        .pop()
                        .trim(),
                )
                .filter(Boolean)
                .forEach((name) => known.add(name));
        }

        const defaultImport = clause
            .replace(/\{[\s\S]*?\}/g, '')
            .replace(/,/g, '')
            .trim();
        if (/^[A-Za-z_$][\w$]*$/.test(defaultImport)) known.add(defaultImport);
    }

    // Anything declared in the file itself.
    for (const match of raw.matchAll(/(?:function|const|let|var|class)\s+([A-Z][\w$]*)/g)) {
        known.add(match[1]);
    }

    // Destructured props: `setup({ App })`, `function StatCard({ icon: Icon })`.
    for (const match of raw.matchAll(/\{([^{}]*)\}\s*(?:=|\)|,)/g)) {
        match[1]
            .split(',')
            .map((part) => part.trim().split(':').pop().trim().split('=')[0].trim())
            .filter((name) => /^[A-Z][\w$]*$/.test(name))
            .forEach((name) => known.add(name));
    }

    const report = (name, shape) => {
        console.log(`  ${file}  ${shape} used but never defined`);
        bad++;
    };

    // Components.
    for (const match of source.matchAll(/<([A-Z][\w$]*)(?:\.[\w$]+)?[\s/>]/g)) {
        if (!known.has(match[1])) report(match[1], `<${match[1]}>`);
    }

    /*
     * Bare identifiers with a property access — what a config constant looks
     * like. This is the shape that got past the first version of this script.
     * Comments and string literals are skipped, since a name inside either is
     * not a use.
     */
    for (const match of source.matchAll(/\b([A-Z][A-Z0-9_]{2,})\.[a-zA-Z_$]/g)) {
        const name = match[1];
        if (known.has(name)) continue;

        const at = match.index ?? 0;
        const lineStart = source.lastIndexOf('\n', at) + 1;
        const before = source.slice(lineStart, at);

        // A comment line, or the name appears inside quotes on this line.
        if (/^\s*(\*|\/\/|\||#)/.test(before)) continue;
        if ((before.match(/['"`]/g)?.length ?? 0) % 2 === 1) continue;

        report(name, `${name}.…`);
    }
    /*
     * Plain function calls — `withFilters(...)`, `formatDate(...)`.
     *
     * The third shape, and the third one to actually ship: a helper used in a
     * page that never imported it throws a ReferenceError the moment that page
     * renders, and neither Vite nor the PHP suite says a word. The first two
     * checks above only look at `<Component>` and `CONSTANT.property`, so a
     * camelCase call walked straight past both.
     *
     * Everything declared, destructured, or taken as a parameter anywhere in
     * the file counts as in scope. That is deliberately generous — this is a
     * linter of last resort, and a false alarm here costs more trust than the
     * occasional miss.
     */
    for (const match of source.matchAll(
        /(?:function|const|let|var|class)\s+([a-z_$][\w$]*)/g,
    )) {
        known.add(match[1]);
    }

    // Method shorthand — `setup({ el, App }) {` declares `setup`.
    for (const match of source.matchAll(/^\s*(?:async\s+)?([a-z_$][\w$]*)\s*\([^)]*\)\s*\{/gm)) {
        known.add(match[1]);
    }

    // Array destructuring — , which is
    // how every piece of state in this codebase is named.
    for (const match of source.matchAll(/\[([^[]]*)\]s*=/g)) {
        match[1]
            .split(',')
            .map((part) => part.trim().split('=')[0].trim())
            .filter((name) => /^[a-z_$][w$]*$/.test(name))
            .forEach((name) => known.add(name));
    }

    // Array destructuring — `const [open, setOpen] = useState()`, which is
    // how every piece of state in this codebase is named.
    for (const match of source.matchAll(/\[([^[\]]*)\]\s*=/g)) {
        match[1]
            .split(',')
            .map((part) => part.trim().split('=')[0].trim())
            .filter((name) => /^[a-z_$][\w$]*$/.test(name))
            .forEach((name) => known.add(name));
    }

    /*
     * Destructuring and parameter lists, lower-case half.
     *
     * One level of nesting is allowed, because a default value is itself a
     * brace: `function Modal({ onClose = () => {} })` hides `onClose` behind
     * an inner `{}` that a flat pattern stops at.
     */
    for (const pattern of [/\{([^{}]*)\}/g, /\{((?:[^{}]|\{[^{}]*\})*)\}/g]) {
        /*
         * Both patterns run, and neither is redundant. The flat one stops at
         * the first inner brace, so it reads `const { post, processing } =`
         * correctly but misses a default value. The nesting one reads the
         * default and, being greedy, swallows a whole function body when the
         * outer brace is a block. Together they cover both; a name learned
         * twice costs nothing, and a name missed is a false alarm.
         */
        for (const match of raw.matchAll(pattern)) {
            match[1]
                .split(',')
                .map((part) => part.trim().split(':').pop().trim().split('=')[0].trim())
                .filter((name) => /^[a-z_$][\w$]*$/.test(name))
                .forEach((name) => known.add(name));
        }
    }

    for (const match of raw.matchAll(/\(([^()]*)\)\s*=>/g)) {
        match[1]
            .split(',')
            .map((part) => part.trim().split('=')[0].trim())
            .filter((name) => /^[a-z_$][\w$]*$/.test(name))
            .forEach((name) => known.add(name));
    }

    for (const name of LOWERCASE_GLOBALS) known.add(name);

    for (const match of source.matchAll(/(^|[^.\w$'"`])([a-z_$][\w$]*)\s*\(/g)) {
        const name = match[2];
        if (known.has(name)) continue;

        const at = match.index ?? 0;
        const lineStart = source.lastIndexOf('\n', at) + 1;
        const before = source.slice(lineStart, at);

        if (/^\s*(\*|\/\/|\||#)/.test(before)) continue;
        if ((before.match(/['"`]/g)?.length ?? 0) % 2 === 1) continue;

        /*
         * Prose, not code. `{count} day(s) of leave` is JSX text and reads to
         * a regex exactly like a call — the pluralising "(s)" is the whole
         * idiom, and it appears on half the screens in this system. A real
         * call sits after an operator, a bracket, or the start of a line;
         * prose sits after a word or a closing brace.
         */
        const previous = before.trimEnd().slice(-1);
        if (previous && !/[({[,;=><!&|?:+\-*/%]/.test(previous)) continue;

        /*
         * `day(s)` — the English pluralisation, wrapped onto its own line so
         * the check above sees nothing before it. There is no function in this
         * codebase taking a bare `s`, and prose that reads as a call is the
         * only thing this shape ever is.
         */
        if (/^\(s\)/.test(source.slice(at + match[0].length - 1))) continue;

        report(name, `${name}()`);
    }
}

/*
 * A fourth shape, and not an import problem at all — but the same *class* of
 * bug this script exists for: silent in the build, fatal in the browser.
 *
 * Inertia's `useForm().transform()` returns undefined. So
 * `form.transform(fn).post(url)` throws a TypeError on `.post` and the submit
 * simply never happens — no error on screen, no failing test, a button that
 * looks fine and does nothing. Two of these were sitting in the codebase
 * before it was written: the bulk-import commit and the batch document filer,
 * both load-bearing, both silently dead.
 *
 * Set the transform, then submit, as two statements.
 */
for (const file of files) {
    const source = stripNonCode(fs.readFileSync(file, 'utf8'));

    for (const match of source.matchAll(
        /\.transform\s*\([\s\S]*?\)\s*\.\s*(post|put|patch|delete|submit)\s*\(/g,
    )) {
        console.log(
            `  ${file}  .transform(…).${match[1]}() — transform() returns undefined; ` +
                'set it and submit as two statements',
        );
        bad++;
    }
}

console.log(
    bad === 0
        ? `  clean — ${files.length} files, every name used is imported or declared`
        : `  ${bad} problem(s)`,
);

process.exit(bad === 0 ? 0 : 1);
