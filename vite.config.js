import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

// The only front-end asset is the Filament theme: both panels load it
// through ->viteTheme(), and there is no page outside Filament.
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/filament/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
