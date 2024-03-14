const { Application } = Shopware;
const ApiService = Shopware.Classes.ApiService;

class LunarPaymentSettingsService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'lunar') {
        super(httpClient, loginService, apiEndpoint);
        this.apiRoute = `${this.getApiBasePath()}`;
    }

    validateSettings(keys) {
        return this.httpClient.post(
            this.apiRoute + `/validate-settings`,
                {
                    keys: keys,
                    headers: this.getBasicHeaders()
                }
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
}

Application.addServiceProvider('LunarPaymentSettingsService', (container) => {
    const initContainer = Application.getContainer('init');

    return new LunarPaymentSettingsService(initContainer.httpClient, container.loginService);
});
