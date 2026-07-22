import path from 'path';

import AnalyzePlugin from 'rollup-plugin-analyzer';
import CompressionPlugin from 'vite-plugin-compression';

export default {
    root: './src/web/assets',
    // CP bundles publish under Craft cpresources; relative base keeps lazy chunks beside the entry (Formie pattern).
    base: '',

    build: {
        outDir: 'field/dist',
        emptyOutDir: true,
        manifest: 'manifest.json',
        sourcemap: true,
        rollupOptions: {
            input: {
                pluginKit: '/field/src/js/plugin-kit-register.ts',
                hyper: '/field/src/js/hyper.ts',
            },
            // (no treeshake.moduleSideEffects — named ctor refs in registerHyperPluginKit keep kit chunks)
        },
    },

    server: {
        origin: 'http://localhost:4010',
        hmr: {
            protocol: 'ws',
        },
    },

    plugins: [
        AnalyzePlugin({
            summaryOnly: true,
            limit: 10,
        }),
        CompressionPlugin({
            filter: /\.(js|mjs|json|css|map)$/i,
        }),
    ],

    resolve: {
        alias: {
            '@': path.resolve('./src/web/assets/field/src'),
        },
        // Resolve kit peers/transitives (lit, @floating-ui/dom, …) from Hyper's
        // node_modules. `preserveSymlinks: true` would force re-listing those at root.
        preserveSymlinks: false,
    },

    optimizeDeps: {
        include: ['lodash-es'],
    },
};
