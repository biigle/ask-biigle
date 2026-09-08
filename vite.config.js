import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import externalize from "vite-plugin-externalize-dependencies";

export default defineConfig(({command}) => ({
    // The chat interface is loaded as a dynamic import, so its URL and the URL of its
    // stylesheet are resolved at runtime. A relative base makes them relative to the
    // URL of the entry script. The Laravel plugin would use the build directory
    // instead, which is not the path the assets are published to.
    base: command === 'build' ? './' : undefined,
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
                // Silence deprecations by the Bootstrap SCSS of biigle/core.
                silenceDeprecations: [
                    'import',
                    'if-function',
                    'color-functions',
                    'global-builtin',
                    'slash-div',
                ],
            },
        },
    },
    build: {
        rolldownOptions: {
            // Ensure that Vue is loaded through the importmap of biigle/core in build.
            external: ['vue'],
        },
    },
}));
