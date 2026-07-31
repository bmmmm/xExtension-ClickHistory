// Mirrors FreshRSS core's own eslint.config.js (github.com/FreshRSS/FreshRSS) so
// the extension is held to the same bar as the code it plugs into. Kept in sync
// by hand; core has no published eslint-config package to depend on instead.
import globals from 'globals';
import js from '@eslint/js';
import neostandard, { resolveIgnoresFromGitignore } from 'neostandard';

export default [
	{
		ignores: resolveIgnoresFromGitignore(),
	},
	{
		files: ['static/**/*.js'],
		languageOptions: {
			globals: {
				...globals.browser,
				// Only used inside the `typeof document === 'undefined'` branch that
				// exports the pure helpers to tests/click-detection.test.js; the file
				// itself is loaded as a plain <script>, never as a CommonJS module.
				module: 'readonly',
			},
			sourceType: 'script',
		},
	},
	{
		files: ['tests/**/*.js'],
		languageOptions: {
			globals: {
				...globals.node,
			},
			sourceType: 'commonjs',
		},
	},
	js.configs.recommended,
	...neostandard(),
	{
		// No `plugins` entry for @stylistic: neostandard/style already registers it,
		// and registering a second copy is an ESLint error rather than a no-op. Core
		// imports it directly because npm hoists the two requests onto one install;
		// here it stays a transitive dependency so its version cannot drift from the
		// one neostandard pins.
		rules: {
			'camelcase': 'off',
			'eqeqeq': 'off',
			'no-empty': ['error', { allowEmptyCatch: true }],
			'no-unused-vars': ['error', {
				args: 'none',
				caughtErrors: 'none',
			}],
			'object-shorthand': 'off',
			'yoda': 'off',
			'@stylistic/indent': ['warn', 'tab', { SwitchCase: 1 }],
			'@stylistic/linebreak-style': ['error', 'unix'],
			'@stylistic/max-len': ['warn', 165],
			'@stylistic/no-tabs': 'off',
			'@stylistic/quotes': ['off', 'single', { avoidEscape: true }],
			'@stylistic/quote-props': ['warn', 'consistent'],
			'@stylistic/semi': ['warn', 'always'],
			'@stylistic/space-before-function-paren': ['warn', {
				anonymous: 'always',
				asyncArrow: 'always',
				named: 'never',
			}],
		},
	},
];
