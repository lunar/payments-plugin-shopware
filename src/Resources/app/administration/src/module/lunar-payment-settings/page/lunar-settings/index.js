import template from './lunar-settings.html.twig';

const { Component, Mixin } = Shopware;
const { object, types } = Shopware.Utils;

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
            isTesting: false,
            isSaveSuccessful: false,
            isTestSuccessful: false,
            config: {},
            configPath: 'LunarPayment.settings.',
            transactionModeConfigKey: 'transactionMode',
            transactionMode: '',
            liveAppConfigKey: 'liveModeAppKey',
            livePublicConfigKey: 'liveModePublicKey',
            testAppConfigKey: 'testModeAppKey',
            testPublicConfigKey: 'testModePublicKey',
            liveAppKeyFilled: false,
            livePublicKeyFilled: false,
            testAppKeyFilled: false,
            testPublicKeyFilled: false,
            showValidationErrors: false,
            showApiErrors: false,
            apiResponseErrors: {},
        };
    },

    created() {
        // this.generateFieldOptions();
    },

    computed: {
        credentialsMissing: function() {
            switch (this.transactionMode) {
                case 'live':
                    return !this.liveAppKeyFilled || !this.livePublicKeyFilled;
                case 'test':
                    return !this.testAppKeyFilled || !this.testPublicKeyFilled
            }
        }
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
            this.checkCredentialsFilled();
            this.showValidationErrors = false;
            this.showApiErrors = false;
        },

        /**
         *
         */
        checkCredentialsFilled() {
            this.liveAppKeyFilled = !!this.getConfigValue(this.liveAppConfigKey);
            this.livePublicKeyFilled = !!this.getConfigValue(this.livePublicConfigKey);
            this.testAppKeyFilled = !!this.getConfigValue(this.testAppConfigKey);
            this.testPublicKeyFilled = !!this.getConfigValue(this.testPublicConfigKey);
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
            this.transactionMode = this.getConfigValue(this.transactionModeConfigKey);
            const titleError = this.$tc('lunar-payment.settings.titleError');

            if (this.credentialsMissing) {
                this.showValidationErrors = true;
                let message = '';

                switch (this.transactionMode) {
                    case "live":
                        !this.liveAppKeyFilled && (message += this.$tc('lunar-payment.settings.liveAppKeyInvalid') + "<br>");
                        !this.livePublicKeyFilled && (message += this.$tc('lunar-payment.settings.livePublicKeyInvalid') + "<br>");
                        break;
                    case "test":
                        !this.testAppKeyFilled && (message += this.$tc('lunar-payment.settings.testAppKeyInvalid') + "<br>");
                        !this.testPublicKeyFilled && (message += this.$tc('lunar-payment.settings.testPublicKeyInvalid') + "<br>");
                }

                this.createNotificationError({
                    title: titleError,
                    message: message,
                });

                this.isLoading = false;
                this.isSaveSuccessful = false;

                return;
            }

            this.isSaveSuccessful = false;
            this.isLoading = true;

            let credentials = {
                transactionMode: this.getConfigValue(this.transactionModeConfigKey),
                liveModeAppKey: this.getConfigValue(this.liveAppConfigKey),
                liveModePublicKey: this.getConfigValue(this.livePublicConfigKey),
                testModeAppKey: this.getConfigValue(this.testAppConfigKey),
                testModePublicKey: this.getConfigValue(this.testPublicConfigKey),
            }

            /**
             * Validate API keys
             */
            this.LunarPaymentSettingsService.validateApiKeys(credentials)
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

                        this.isLoading = false;
                        this.isSaveSuccessful = true;
                    }).catch((errorResponse) => {
                        this.createNotificationError({
                            title: titleError,
                            message: this.$tc('lunar-payment.settings.generalSaveError'),
                        });
                        this.isLoading = false;
                        this.isSaveSuccessful = false;
                    });

                    this.isLoading = false;

            }).catch((errorResponse) => {
                let apiResponseErrors = errorResponse.response.data.errors;

                Object.entries(apiResponseErrors).forEach(([key, errorMessage]) => {
                    // title+detail added for SW6.5
                    this.createNotificationError({
                        title: errorMessage.title || titleError,
                        message: errorMessage.detail || errorMessage,
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
            let errorFieldNotBlank = {
                code: 1,
                detail: this.$tc('lunar-payment.messageNotBlank'),
            };

            if (config !== this.config) {
                this.config = config;
            }

            if (this.showValidationErrors) {

                switch (this.transactionMode) {
                    case 'live':
                        if (element.name === `${this.configPath}${this.liveAppConfigKey}` && !this.liveAppKeyFilled) {
                            element.config.error = errorFieldNotBlank;
                        }
                        if (element.name === `${this.configPath}${this.livePublicConfigKey}` && !this.livePublicKeyFilled) {
                            element.config.error = errorFieldNotBlank;
                        }
                        break;

                    case 'test':
                        if (element.name === `${this.configPath}${this.testAppConfigKey}` && !this.testAppKeyFilled) {
                            element.config.error = errorFieldNotBlank;
                        }
                        if (element.name === `${this.configPath}${this.testPublicConfigKey}` && !this.testPublicKeyFilled) {
                            element.config.error = errorFieldNotBlank;
                        }
                }
            }

            if (this.showApiErrors) {
                if (
                    element.name === `${this.configPath}${this.liveAppConfigKey}`
                    && this.apiResponseErrors.hasOwnProperty(this.liveAppConfigKey)
                ) {
                    element.config.error = {code: 1, detail: this.$tc('lunar-payment.settings.liveAppKeyInvalid')};
                }
                if (
                    element.name === `${this.configPath}${this.livePublicConfigKey}`
                    && this.apiResponseErrors.hasOwnProperty(this.livePublicConfigKey)
                ) {
                    element.config.error = {code: 1, detail: this.$tc('lunar-payment.settings.livePublicKeyInvalid')};
                }
                if (
                    element.name === `${this.configPath}${this.testAppConfigKey}`
                    && this.apiResponseErrors.hasOwnProperty(this.testAppConfigKey)
                ) {
                    element.config.error = {code: 1, detail: this.$tc('lunar-payment.settings.testAppKeyInvalid')};
                }
                if (
                    element.name === `${this.configPath}${this.testPublicConfigKey}`
                    && this.apiResponseErrors.hasOwnProperty(this.testPublicConfigKey)
                ) {
                    element.config.error = {code: 1, detail: this.$tc('lunar-payment.settings.testPublicKeyInvalid')};
                }
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
                && (element.name.includes('transactionMode')
                    || element.name.includes('testModeAppKey')
                    || element.name.includes('testModePublicKey'))
            ) {
                return false;
            }

            return true;
        }
    },
});
