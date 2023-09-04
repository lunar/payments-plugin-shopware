import template from './lunar-payment-tab.html.twig';

const { Component, Context, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('lunar-payment-tab', {
    template,

    inject: ['LunarPaymentService', 'repositoryFactory'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            lunarTransactions: [],
            isLoading: true
        };
    },

    created() {
        this.loadData();
    },

    computed: {
        orderRepository() {
            return this.repositoryFactory.create('order');
        }
    },

    watch: {
        '$route'() {
            this.resetDataAttributes();
            this.createdComponent();
        }
    },

    methods: {
        resetDataAttributes() {
            this.lunarTransactions = [];
            this.isLoading = true;
        },

        reloadPaymentDetails() {
            this.resetDataAttributes();
            this.loadData();
        },

        loadData() {

            const self = this;

            const orderId = this.$route.params.id;
            const criteria = new Criteria();
            criteria.addAssociation('transactions');

            this.orderRepository.get(orderId, Context.api, criteria).then((order) => {

                if (!order.transactions) {
                    return;
                }

                order.transactions.forEach((orderTransaction) => {
                    if ('1a9bc76a3c244278a51a2e90c1e6f040' !== orderTransaction.paymentMethodId) {
                        this.createNotificationError({
                            title: this.$tc('lunar-payment.paymentDetails.notifications.genericErrorMessage'),
                            message: this.$tc('lunar-payment.paymentDetails.notifications.orderHaveOtherPayments')
                        });
                    }

                    this.isLoading = false;
                });

                this.LunarPaymentService.fetchLunarTransactions(orderId)
                    .then((response) => {
                        this.isLoading = false;
                        this.lunarTransactions.push(response.transactions);
                    })
                    .catch(() => {
                        this.createNotificationError({
                            title: this.$tc('lunar-payment.paymentDetails.notifications.genericErrorMessage'),
                            message: this.$tc('lunar-payment.paymentDetails.notifications.couldNotRetrieveMessage')
                        });

                        this.isLoading = false;
                    });
            });
        }
    }
});
