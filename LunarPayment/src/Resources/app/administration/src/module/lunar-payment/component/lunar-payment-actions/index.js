import template from "./lunar-payment-actions.html.twig";
import "./lunar-payment-actions.scss";

const { Component, Mixin } = Shopware;

Component.register("lunar-payment-actions", {
    template,

    inject: ["LunarPaymentService"],

    mixins: [Mixin.getByName("notification")],

    data() {
        return {
            isLoading: false,
            isSuccessful: false,
        };
    },

    props: {
        orderId: {
            type: String,
            required: true
        },
        lunarTransactionId: {
            type: String,
            required: true
        },
        lastTransactionType: {
            type: String,
            required: true
        }
    },

    computed: {
        isCapturePossible() {
            return 'authorize' === this.lastTransactionType;
        },

        isRefundPossible() {
            return 'capture' === this.lastTransactionType;
        },

        isCancelPossible() {
            return 'authorize' === this.lastTransactionType;
        },

        paymentData() {
            return {
                'orderId': this.orderId,
                'lunarTransactionId': this.lunarTransactionId,
            };
        }
    },

    methods: {
        capturePayment() {
            this.isLoading = true;

            this.LunarPaymentService.capturePayment(this.paymentData)
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc("lunar-payment.paymentDetails.notifications.captureSuccessTitle"),
                        message: this.$tc("lunar-payment.paymentDetails.notifications.captureSuccessMessage"),
                    });

                    this.isSuccessful = true;

                    this.$emit("reload");
                })
                .catch((errorResponse) => {
                    let errors = errorResponse.response.data.errors;

                    errors.forEach((errorMessage) => {
                        this.createNotificationError({
                            title: this.$tc("lunar-payment.paymentDetails.notifications.captureErrorTitle"),
                            message: errorMessage,
                        });
                    })

                    this.isLoading = false;
                });
        },

        refundPayment() {
            this.isLoading = true;

            this.LunarPaymentService.refundPayment(this.paymentData)
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc("lunar-payment.paymentDetails.notifications.refundSuccessTitle"),
                        message: this.$tc("lunar-payment.paymentDetails.notifications.refundSuccessMessage"),
                    });

                    this.isSuccessful = true;

                    this.$emit("reload");
                })
                .catch((errorResponse) => {
                    let errors = errorResponse.response.data.errors;

                    errors.forEach((errorMessage) => {
                        this.createNotificationError({
                            title: this.$tc("lunar-payment.paymentDetails.notifications.refundErrorTitle"),
                            message: errorMessage,
                        });
                    })


                    this.isLoading = false;
                });
        },

        cancelPayment() {
            this.isLoading = true;

            this.LunarPaymentService.cancelPayment(this.paymentData)
                .then(() => {
                    this.createNotificationSuccess({
                        title: this.$tc("lunar-payment.paymentDetails.notifications.cancelSuccessTitle"),
                        message: this.$tc("lunar-payment.paymentDetails.notifications.cancelSuccessMessage"),
                    });

                    this.isSuccessful = true;

                    this.$emit("reload");
                })
                .catch((errorResponse) => {
                    let errors = errorResponse.response.data.errors;

                    errors.forEach((errorMessage) => {
                        this.createNotificationError({
                            title: this.$tc("lunar-payment.paymentDetails.notifications.cancelErrorTitle"),
                            message: errorMessage,
                        });
                    })


                    this.isLoading = false;
                });
        },

        reloadPaymentDetails() {
            this.$emit('reload');
        },
    },
});
