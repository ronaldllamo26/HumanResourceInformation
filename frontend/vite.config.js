import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import fs from 'node:fs';

function getPageEntries(dir = path.resolve(__dirname, 'resources/js/Pages')) {
    let results = [];
    if (!fs.existsSync(dir)) return results;
    const list = fs.readdirSync(dir);
    list.forEach(file => {
        const filePath = path.join(dir, file);
        const stat = fs.statSync(filePath);
        if (stat && stat.isDirectory()) {
            results = results.concat(getPageEntries(filePath));
        } else if (file.endsWith('.jsx')) {
            const rel = path.relative(__dirname, filePath).replace(/\\/g, '/');
            results.push(rel);
        }
    });
    return results;
}

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/app.jsx',
                ...getPageEntries(),
            ],
            publicDirectory: '../backend/public',
            refresh: [
                '../backend/resources/views/**',
                '../backend/routes/**',
                '../backend/app/Http/Controllers/**',
            ],
        }),
        react(),
    ],
    build: {
        emptyOutDir: true,
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
});

