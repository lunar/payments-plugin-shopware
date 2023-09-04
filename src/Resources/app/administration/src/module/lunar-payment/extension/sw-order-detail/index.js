import template from './sw-order-detail.html.twig';

const { Component, Context } = Shopware;
const { Criteria } = Shopware.Data;

Component.override('sw-order-detail', {
    template,

    data() {
        return {
            isLunarPayment: false
        };
    },

    computed: {
        showTabs() {
            return this.isLunarPayment;
        }
    },

    watch: {
        orderId: {
            deep: true,
            handler() {
                if (!this.orderId) {
                    this.isLunarPayment = false;
                    return;
                }

                const lunarTransactionRepository = this.repositoryFactory.create('lunar_transaction');

                const criteria = new Criteria();
                criteria.addFilter(Criteria.equals('orderId', this.orderId));

                lunarTransactionRepository.search(criteria, Context.api).then((lunarTransactions) => {

                    if (!lunarTransactions) {
                        return;
                    }

                    this.isLunarPayment = true;
                });
            },
            immediate: true
        }
    }
});
