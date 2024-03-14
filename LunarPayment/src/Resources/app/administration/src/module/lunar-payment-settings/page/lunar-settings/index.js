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
            showApiErrors: false,
            apiResponseErrors: {},
            cardLogoURLKey: 'cardLogoURL',
            mobilePayLogoURLKey: 'mobilePayLogoURL',
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
        getConfigValue(field) {
            const defaultConfig = this.$refs.systemConfig.actualConfigData.null;
            const salesChannelId = this.$refs.systemConfig.currentSalesChannelId;

            if (salesChannelId === null) {
                return this.config[`${this.configPath}${field}`];
            }
            return this.config[`${this.configPath}${field}`] || defaultConfig[`${this.configPath}${field}`];
        },

        /**
         *
         */
        onSave() {
            this.isLoading = true; 
            const titleError = this.$tc('lunar-payment.settings.titleError');
           
            this.isSaveSuccessful = false;

            let data = {
                cardLogoURL: this.getConfigValue(this.cardLogoURLKey),
                mobilePayLogoURL: this.getConfigValue(this.mobilePayLogoURLKey),
                // cardAppKey: this.getConfigValue(this.appConfigKey),
                // cardPublicKey: this.getConfigValue(this.publicConfigKey),
            }

            /**
             * Validate API keys
             */
            this.LunarPaymentSettingsService.validateSettings(data)
                .then((response) => {

                    /** Clear errors. */
                    this.$refs.systemConfig.config.forEach((configElement) => {
                        configElement.elements.forEach((child) => {
                            delete child.config.error;
                        });
                    });

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

            }).catch((errorResponse) => {
                let apiResponseErrors = errorResponse.response.data.errors;

                Object.entries(apiResponseErrors).forEach(([key, errorMessage]) => {
                    this.createNotificationError({
                        title: titleError,
                        message: errorMessage,
                    })
                });

                this.showApiErrors = true;
                this.apiResponseErrors = apiResponseErrors;
                this.isLoading = false;
                this.isSaveSuccessful = false;
            });

        },

        /**
         *
         */
        getBind(element, config) {
            // if (config !== this.config) {
            //     this.config = config;
            // }

            
            if (this.showApiErrors) {
                if (
                    element.name === `${this.configPath}${this.cardLogoURLKey}`
                    && this.apiResponseErrors.hasOwnProperty(this.cardLogoURLKey)
                ) {
                    element.config.error = {code: 1, detail: this.$tc('lunar-payment.settings.cardLogoURLInvalid')};
                }
                if (
                    element.name === `${this.configPath}${this.mobilePayLogoURLKey}`
                    && this.apiResponseErrors.hasOwnProperty(this.mobilePayLogoURLKey)
                ) {
                    element.config.error = {code: 1, detail: this.$tc('lunar-payment.settings.mobilePayLogoURLInvalid')};
                }

                // if (
                //     element.name === `${this.configPath}${this.appConfigKey}`
                //     && this.apiResponseErrors.hasOwnProperty(this.appConfigKey)
                // ) {
                //     element.config.error = {code: 1, detail: this.$tc('lunar-payment.settings.appKeyInvalid')};
                // }
                // if (
                //     element.name === `${this.configPath}${this.publicConfigKey}`
                //     && this.apiResponseErrors.hasOwnProperty(this.publicConfigKey)
                // ) {
                //     element.config.error = {code: 1, detail: this.$tc('lunar-payment.settings.publicKeyInvalid')};
                // }
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
    },
});
