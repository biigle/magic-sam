import Plugin from './components/settingsTabPlugin.vue';
import {SettingsTabPlugins} from './import.js';

/**
 * The plugin component set the SAM throttle interval.
 *
 * @type {Object}
 */
if (SettingsTabPlugins) {
    SettingsTabPlugins.magicSam = Plugin;
}
