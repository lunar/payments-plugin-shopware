const { Application } = Shopware;
const ApiService = Shopware.Classes.ApiService;

class LunarPaymentService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'lunar') {
        super(httpClient, loginService, apiEndpoint);
        this.apiRoute = `${this.getApiBasePath()}`;
    }

    fetchLunarTransactions(orderId) {
        return this.httpClient.post(
            this.apiRoute + `/fetch-transactions`,
            {
                params: {
                    orderId: orderId
                },
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    capturePayment(params) {
        return this.httpClient.post(
            this.apiRoute + `/capture`,
            {
                params: params,
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    refundPayment(params) {
        return this.httpClient.post(
            this.apiRoute + `/refund`,
            {
                params: params,
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }

    cancelPayment(params) {
        return this.httpClient.post(
            this.apiRoute + `/cancel`,
            {
                params: params,
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
}

Application.addServiceProvider('LunarPaymentService', (container) => {
    const initContainer = Application.getContainer('init');

    return new LunarPaymentService(initContainer.httpClient, container.loginService);
});
