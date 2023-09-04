/**
 * snippet
 */
import enGB from '../../snippet/en_GB.json';

/**
 * component
 */
import './component/lunar-payment-plugin-icon';

/**
 * extension
 */
import './extension/sw-settings-index/index';

/**
 * page
 */
import './page/lunar-settings';


let moduleConfig = {
    type: 'plugin',
    name: 'LunarPayment',
    title: 'Lunar Payment',
    description: 'Lunar Payment plugin',
    version: '1.0.0',
    targetVersion: '1.0.0',
    icon: 'default-action-settings',

    snippets: {
        'en-GB': enGB
    },

    routes: {
        index: {
            component: 'lunar-settings',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index',
            },
        },
    },

    settingsItem: {
        group: 'plugins',
        to: 'lunar.payment.settings.index',
        iconComponent: 'lunar-payment-plugin-icon',
        backgroundEnabled: false,
    },

 }

 /**
  * REGISTER
  */
 Shopware.Module.register('lunar-payment-settings', moduleConfig);
