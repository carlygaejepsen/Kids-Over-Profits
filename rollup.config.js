import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';

export default {
    input: 'js/db-form/src/index.js',
    output: {
        file: 'js/db-form/dist/db-form.bundle.js',
        format: 'iife',
        name: 'KOPDbFormBundle',
        sourcemap: true
    },
    plugins: [
        resolve({ browser: true }),
        commonjs()
    ]
};
