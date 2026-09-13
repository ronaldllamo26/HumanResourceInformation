import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '@inertiajs/react': path.resolve(__dirname, 'resources/js/lib/inertia-adapter.jsx'),
        },
    },
    server: {
        port: 5173,
        proxy: {
            '/api': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
            },
            '/sanctum': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
            },
            '/login': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
                bypass: (req) => {
                    if (req.method === 'GET' && req.headers.accept?.includes('text/html')) {
                        return '/index.html';
                    }
                },
            },
            '/logout': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
            },
            '/forgot-password': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
                bypass: (req) => {
                    if (req.method === 'GET' && req.headers.accept?.includes('text/html')) {
                        return '/index.html';
                    }
                },
            },
            '/reset-password': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
                bypass: (req) => {
                    if (req.method === 'GET' && req.headers.accept?.includes('text/html')) {
                        return '/index.html';
                    }
                },
            },
            '/dashboard': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
                bypass: (req) => {
                    if (req.method === 'GET' && req.headers.accept?.includes('text/html')) {
                        return '/index.html';
                    }
                },
            },
            '/hr': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
                bypass: (req) => {
                    if (req.method === 'GET' && req.headers.accept?.includes('text/html')) {
                        return '/index.html';
                    }
                },
            },
            '/settings': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
                bypass: (req) => {
                    if (req.method === 'GET' && req.headers.accept?.includes('text/html')) {
                        return '/index.html';
                    }
                },
            },
            '/session': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
            },
            '/storage': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
                autoRewrite: true,
            },
        },
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
    },
});
