import { defineConfig } from 'vite';
import fs from 'fs';
import FullReload from 'vite-plugin-full-reload';

let input = {
    base: './resources/assets/js/base/index.js',
    app: './resources/assets/js/app/index.js',
    auth: './resources/assets/js/auth/index.js',
    admin: './resources/assets/js/admin/index.js',
    style: './resources/assets/css/index.css'
};

if (fs.existsSync('./resources/assets/js/install/index.js')) {
    input.install = './resources/assets/js/install/index.js';
}

export default defineConfig({
    root: './', // Source files directory
    base: '/', // Base public path for assets
    publicDir: 'resources/static',
    build: {
        manifest: true,
        outDir: 'public', // Where Vite outputs the built assets
        assetsDir: 'assets', // The directory where Vite places the generated assets
        emptyOutDir: false, // Clean the output directory before each build
        rollupOptions: {
            input: input,
        },
    },
    optimizeDeps: {
        include: [
            'alpinejs',
            '@alpinejs/mask',
            '@ryangjchandler/alpine-tooltip',
            'apexcharts',
            'card-validator',
            'eventsource-parser',
            'fast-blurhash',
            'filesize',
            'highlight.js',
            'jwt-decode',
            'remarkable',
            'restricted-input',
            'wavesurfer.js',
            'katex',
        ], // Explicitly include required dependencies
    },
    server: {
        strictPort: true,
        port: 5173
    },
    plugins: [
        FullReload(['./resources/**/*.twig']) // Watch Twig files for changes
    ]
});
