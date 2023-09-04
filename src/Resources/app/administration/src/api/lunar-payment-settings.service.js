const { Application } = Shopware;
const ApiService = Shopware.Classes.ApiService;

class LunarPaymentSettingsService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'lunar') {
        super(httpClient, loginService, apiEndpoint);
        this.apiRoute = `${this.getApiBasePath()}`;
    }

    validateApiKeys(keys) {
        return this.httpClient.post(
            this.apiRoute + `/validate-api-keys`,
                {
                    keys: keys,
                    headers: this.getBasicHeaders()
                }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }

    // getSetting(key, salesChannelId = null) {
    //     return this.httpClient.post(
    //         this.apiRoute + `/get-setting`,
    //             {
    //                 key: key,
    //                 salesChannelId: salesChannelId,
    //                 headers: this.getBasicHeaders()
    //             }
    //         )
    //         .then((response) => {
    //             return ApiService.handleResponse(response);
    //         });
    // }
}

Application.addServiceProvider('LunarPaymentSettingsService', (container) => {
    const initContainer = Application.getContainer('init');

    return new LunarPaymentSettingsService(initContainer.httpClient, container.loginService);
});
