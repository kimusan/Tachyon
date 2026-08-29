import { koComputable } from 'External/ko';
import { i18n, translateTrigger } from 'Common/Translator';
import { AbstractViewSettings } from 'Knoin/AbstractViews';

export class AdminSettingsLogging extends AbstractViewSettings {
	constructor() {
		super();

		this.addSettings([
			'logsEnable',
			'logsPath',
			'logsFilename',
			'logsLevel',
			'logsTimeZone',
			'logsHidePasswords',
			'logsAuthLogging',
			'logsAuthLoggingFilename',
			'logsAuthLoggingFormat',
			'debugEnable',
			'debugJavascript',
			'debugCss'
		]);

		// RFC 5424 section 6.2.1, lowest number is the most severe. Named rather
		// than left as a number, since "4" tells an admin nothing about what it
		// will and will not write.
		this.levelOptions = koComputable(() => {
			translateTrigger();
			return [
				{ id: 0, name: '0 - ' + i18n('TAB_LOGS/LEVEL_EMERGENCY') },
				{ id: 1, name: '1 - ' + i18n('TAB_LOGS/LEVEL_ALERT') },
				{ id: 2, name: '2 - ' + i18n('TAB_LOGS/LEVEL_CRITICAL') },
				{ id: 3, name: '3 - ' + i18n('TAB_LOGS/LEVEL_ERROR') },
				{ id: 4, name: '4 - ' + i18n('TAB_LOGS/LEVEL_WARNING') },
				{ id: 5, name: '5 - ' + i18n('TAB_LOGS/LEVEL_NOTICE') },
				{ id: 6, name: '6 - ' + i18n('TAB_LOGS/LEVEL_INFO') },
				{ id: 7, name: '7 - ' + i18n('TAB_LOGS/LEVEL_DEBUG') }
			];
		});
	}
}
