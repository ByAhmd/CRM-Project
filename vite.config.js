import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        // Only what a page actually loads: the panel theme, registered with
        // Filament through ->viteTheme(), and the calendar page's FullCalendar
        // bootstrap, pulled in by resources/views/filament/pages/calendar.blade.php.
        // The admin panel is the whole front end; there is no non-panel stylesheet.
        laravel({
            input: ['resources/js/calendar.js', 'resources/css/filament/admin/theme.css'],
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
