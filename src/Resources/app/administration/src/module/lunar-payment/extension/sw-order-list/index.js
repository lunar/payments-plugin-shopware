import template from './sw-order-list.html.twig';

Shopware.Component.override('sw-order-list', {
    template,

    inject: ['systemConfigApiService'],

    methods: {
        /**
         * @TODO the setting value must be accesed before create this component
         * But in beforeCreate hook, we don't have systemConfigApiService injected
         * It must be another way to handle this here
         */
        async getPluginConfig() {
            return await this.systemConfigApiService.getValues('LunarPayment.settings');
        },

        getOrderColumns() {
            const baseColumns = this.$super('getOrderColumns');

            // if (lunarConfig.showLunarTransactionInOrderList) {
            if (false) {
                baseColumns.splice(1, 0, {
                    property: 'lunarPaymentTransactionId',
                    label: 'lunar-payment.order-list.transactionId',
                    allowResize: true
                });
            }

            return baseColumns;
        }
    }
});
