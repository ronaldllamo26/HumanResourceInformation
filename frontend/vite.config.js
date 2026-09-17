import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.jsx',
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

