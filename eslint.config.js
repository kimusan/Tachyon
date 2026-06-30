const globals = require('globals');
const js = require('@eslint/js');

module.exports = [
	{
		ignores: ['node_modules/**', 'vendors/**', 'snappymail/**']
	},
	{
		files: ['dev/**/*.js'],
		languageOptions: {
			ecmaVersion: 11,
			sourceType: 'module',
			globals: {
				...globals.browser,
				...globals.node,
				rainloopI18N: 'readonly',
				rainloopTEMPLATES: 'readonly',
				rl: 'readonly',
				shortcuts: 'readonly',
				progressJs: 'readonly',
				openpgp: 'readonly',
				CKEDITOR: 'readonly',
				Squire: 'readonly',
				SquireUI: 'readonly',
				ko: 'readonly',
				hasher: 'readonly',
				Crossroads: 'readonly',
				Jua: 'readonly',
				BSN: 'readonly',
				mailvelope: 'readonly',
				IDN: 'readonly',
				TurndownService: 'readonly',
				marked: 'readonly'
			}
		},
		rules: {
			...js.configs.recommended.rules,
			'no-cond-assign': 0,
			'no-mixed-spaces-and-tabs': 'off',
			'max-len': [
				'error',
				120,
				2,
				{
					ignoreComments: true,
					ignoreUrls: true,
					ignoreTrailingComments: true,
					ignorePattern: '(^\\s*(const|let|var)\\s.+=\\s*require\\s*\\(|^import\\s.+\\sfrom\\s.+;$)'
				}
			],
			'no-constant-condition': ['error', { checkLoops: false }]
		}
	}
];
