import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import externalize from "vite-plugin-externalize-dependencies";

export default defineConfig({
    plugins: [
        // Ensure that Vue is loaded through the importmap of biigle/core in dev mode.
        externalize({externals: ["vue"]}),
        laravel({
            publicDirectory: 'src',
            buildDirectory: 'public',
            input: [
                'src/resources/assets/js/chatbot.js',
            ],
            hotFile: 'hot',
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
                compilerOptions: {
                    whitespace: 'preserve',
                },
            },
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                // SCSS of biigle/core, relative to the module root in vendor/biigle/*.
                loadPaths: ['../../../resources/assets/sass'],
            },
        },
    },
    build: {
        rolldownOptions: {
            // Ensure that Vue is loaded through the importmap of biigle/core in build.
            external: ['vue'],
        },
    },
});
