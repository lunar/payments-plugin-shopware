<?php declare(strict_types=1);

namespace Lunar\Payment\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use Lunar\Lunar as ApiClient;
use Lunar\Exception\ApiException;
use Lunar\Payment\Helpers\PluginHelper;
use Lunar\Payment\Helpers\ValidationHelper;

/**
 * @Route(defaults={"_routeScope"={"administration"}})
 */
class SettingsController extends AbstractController
{
    private const  CONFIG_PATH = PluginHelper::PLUGIN_CONFIG_PATH;

    private array $errors = [];
    private array $livePublicKeys = [];
    private array $testPublicKeys = [];

    public function __construct(
       private SystemConfigService $systemConfigService
    ) {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * Temporary added - we don't validate the keys
     * 
     * @Route("/api/lunar/validate-api-keys", name="api.lunar.validate.api.keys", methods={"POST"})
     */
    public function validateApiKeys(Request $request, Context $context): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Success',
        ], 200);
    }

    /**
     * @TODO add validate logo URL logic
     */

    // /**
    //  * @Route("/api/lunar/validate-api-keys", name="api.lunar.validate.api.keys", methods={"POST"})
    //  */
    // public function validateApiKeys(Request $request, Context $context): JsonResponse
    // {
    //     $liveAppKeyName = 'liveModeAppKey';
    //     $livePublicKeyName = 'liveModePublicKey';
    //     $testAppKeyName = 'testModeAppKey';
    //     $testPublicKeyName = 'testModePublicKey';

    //     $settingsKeys = $request->request->all()['keys'] ?? [];

    //     // validate all fields regardless of transactionMode
    //     $this->validateLiveAppKey($settingsKeys[$liveAppKeyName] ?? '');
    //     $this->validateLivePublicKey($settingsKeys[$livePublicKeyName] ?? '');
    //     $this->validateTestAppKey($settingsKeys[$testAppKeyName] ?? '');
    //     $this->validateTestPublicKey($settingsKeys[$testPublicKeyName] ?? '');

    //     if (!empty($this->errors)) {
    //         return new JsonResponse([
    //             'message' => 'Error',
    //             'errors'=> $this->errors,
    //         ], 400);
    //     }


    //     return new JsonResponse([
    //         'message' => 'Success',
    //     ], 200);
    // }


    /**
     * LIVE KEYS VALIDATION
     */
    private function validateLiveAppKey($liveAppKeyValue)
    {
        if (!$liveAppKeyValue) {
            return;
        }

        $apiClient = new ApiClient($liveAppKeyValue);

        try {
            $identity = $apiClient->apps()->fetch();

        } catch (ApiException $exception) {
            $message = "The app key doesn't seem to be valid. <br>";
            $message = ValidationHelper::handleExceptions($exception, $message);

            $this->errors['liveModeAppKey'] = $message;
        }

        try {
            $merchants = $apiClient->merchants()->find($identity['id'] ?? '');
            if ($merchants) {
                foreach ($merchants as $merchant) {
                    if ( ! $merchant['test']) {
                        $this->livePublicKeys[] = $merchant['key'];
                    }
                }
            }
        } catch (ApiException $exception) {
            // handle this bellow
        }

        if (empty($this->livePublicKeys)) {
            $this->errors['liveModeAppKey'] = 'The private key is not valid or set to test mode.';
        }
    }

    /**
     *
     */
    private function validateLivePublicKey($livePublicKeyValue)
    {
        if (!$livePublicKeyValue) {
            return;
        }

        if (!empty($this->livePublicKeys)) {
            /** Check if the public key exists among the saved ones. */
            if (!in_array($livePublicKeyValue, $this->livePublicKeys)) {
                $this->errors['liveModePublicKey'] = 'The public key doesn\'t seem to be valid.';
            }
        }
    }

    /**
     * TEST KEYS VALIDATION
     */
    private function validateTestAppKey($testAppKeyValue)
    {
        if (!$testAppKeyValue) {
            return;
        }

        $apiClient = new ApiClient($testAppKeyValue);

        try {
            $identity = $apiClient->apps()->fetch();

        } catch (ApiException $exception) {
            $message = "The test app key doesn't seem to be valid. <br>";
            $message = ValidationHelper::handleExceptions($exception, $message);

            $this->errors['testModeAppKey'] = $message;
        }


        try {
            $merchants = $apiClient->merchants()->find($identity['id'] ?? '');
            if ($merchants) {
                foreach ($merchants as $merchant) {
                    if ($merchant['test']) {
                        $this->testPublicKeys[] = $merchant['key'];
                    }
                }
            }
        } catch (ApiException $exception) {
            // handle this bellow
        }

        if (empty($this->testPublicKeys)) {
            $this->errors['testModeAppKey'] = 'The test private key is not valid or set to live mode.';
        }
    }

    /**
     *
     */
    private function validateTestPublicKey($testPublicKeyValue)
    {
        if (!$testPublicKeyValue) {
            return;
        }

        if (!empty($this->testPublicKeys)) {
            /** Check if the public key exists among the saved ones. */
            if (!in_array($testPublicKeyValue, $this->testPublicKeys)) {
                $this->errors['testModePublicKey'] = 'The test public key doesn\'t seem to be valid.';
            }
        }
    }

    /**
     * @//Route("/api/lunar/get-setting", name="api.lunar.get.setting", methods={"GET"})
     */
    // public function getSetting(Request $request, Context $context): JsonResponse
    // {
    //     $errors = [];

    //     $settingsKey = $request->request->get('key');
    //     $salesChannelId = $request->request->get('salesChannelId') ?? null;

    //     $settingValue = $this->systemConfigService->get(self::CONFIG_PATH . $settingKey, $salesChannelId);

    //     if (!empty($errors)) {
    //         return new JsonResponse([
    //             'message' => 'Error',
    //             'errors'  => $errors,
    //         ], 400);
    //     }

    //     return new JsonResponse([
    //         'message' => 'Success',
    //         'data'    => $settingValue,
    //     ], 200);
    // }
}
