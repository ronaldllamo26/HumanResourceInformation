import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

/*
 * Deliberately plain, and deliberately *not* the Laravel setup next door.
 *
 * This project has no `laravel-vite-plugin` and no manifest: its output is a
 * static site Vercel serves on its own, where the HRIS build exists only to
 * be picked up by a blade `@vite()` call. Same tool, two different jobs — and
 * conflating them is what would make this folder look like part of the app.
 */
export default defineConfig({
    plugins: [react()],
});
