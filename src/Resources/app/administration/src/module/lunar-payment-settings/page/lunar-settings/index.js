import template from './lunar-settings.html.twig';

const { Component, Mixin } = Shopware;
const { object } = Shopware.Utils;

Component.register('lunar-settings', {
    template,

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],

    props: {
    },

    inject: [
        'LunarPaymentSettingsService',
    ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
            config: {},
            configPath: 'LunarPayment.settings.',
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    methods: {
        /**
         *
         */
        saveFinish() {
            this.isSaveSuccessful = false;
        },

        /**
         *
         */
        onConfigChange(config) {
            this.config = config;
        },

        /**
         *
         */
        onSave() {
            this.isLoading = true; 
            const titleError = this.$tc('lunar-payment.settings.titleError');

            /**
             * Save configuration.
             */
            this.$refs.systemConfig.saveAll().then(() => {
                this.createNotificationSuccess({
                    title: this.$tc('lunar-payment.settings.titleSuccess'),
                    message: this.$tc('lunar-payment.settings.generalSuccess'),
                });

                this.isSaveSuccessful = true;
                
            }).catch((errorResponse) => {
                console.log(errorResponse);

                this.createNotificationError({
                    title: titleError,
                    message: this.$tc('lunar-payment.settings.generalSaveError'),
                });
            });

            this.isLoading = false;

        },

        /**
         *
         */
        getBind(element, config) {
            if (config !== this.config) {
                this.config = config;
            }

            let originalElement;

            if (config !== this.config) {
                this.config = config;
            }

            this.$refs.systemConfig.config.forEach((configElement) => {
                configElement.elements.forEach((child) => {
                    if (child.name === element.name) {
                        if (element.config.error) {
                            child.config.error = element.config.error;
                        }
                        originalElement = child;
                        return;
                    }
                });
            });

            return originalElement || element;

        },

        /**
         *
         */
        getElementBind(element) {
            const bind = object.deepCopyObject(element);

            if (['single-select', 'multi-select'].includes(bind.type)) {
                bind.config.labelProperty = 'name';
                bind.config.valueProperty = 'id';
            }

            return bind;
        },

        /**
         *
         */
        hideField(element) {
            if (
                ("debug" !== location.href.split('?')[1]) // location.search returns null because of '#' used in admin url
                && (element.name.includes('TransactionMode')
                    || element.name.includes('TestMode'))
            ) {
                return false;
            }

            return true;
        }
    },
});
