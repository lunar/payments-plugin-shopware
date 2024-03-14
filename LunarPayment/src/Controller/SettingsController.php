<?php declare(strict_types=1);

namespace Lunar\Payment\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * @Route(defaults={"_routeScope"={"api"}})
 */
class SettingsController extends AbstractController
{
    private array $errors = [];

    public function __construct(
       private SystemConfigService $systemConfigService
    ) {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @Route("/api/lunar/validate-settings", name="api.lunar.validate.settings", methods={"POST"})
     */
    public function validatesettings(Request $request, Context $context): JsonResponse
    {
        $settingsData = $request->request->all()['keys'] ?? [];

        $cardLogoURLKey = 'cardLogoURL';
        $mobilePayLogoURLKey = 'mobilePayLogoURL';
        $this->validateLogoURL($settingsData[$cardLogoURLKey], $cardLogoURLKey);
        $this->validateLogoURL($settingsData[$mobilePayLogoURLKey], $mobilePayLogoURLKey);


        if (!empty($this->errors)) {
            return new JsonResponse([
                'message' => 'Error',
                'errors'=> $this->errors,
            ], 400);
        }
        
        return new JsonResponse([
            'message' => 'Success',
        ], 200);
    }


    /**
     * @return void
     */
    private function validateLogoURL($url, $errorKey)
    {
        if (!preg_match('/^https:\/\//', $url)) {
            $this->errors[$errorKey] = 'Logo URL must start with https://. ' . "($errorKey)";
            return;
		}

        if (!$this->fileExists($url)) {
            $this->errors[$errorKey] = 'Logo URL is invalid. ' . "($errorKey)";
        }
    }

    /**
     * @return bool
     */
    private function fileExists($url)
    {
        $valid = true;

        $c = curl_init();
        curl_setopt($c, CURLOPT_URL, $url);
        curl_setopt($c, CURLOPT_HEADER, 1);
        curl_setopt($c, CURLOPT_NOBODY, 1);
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_FRESH_CONNECT, 1);
        
        if(!curl_exec($c)){
            $valid = false;
        }

        curl_close($c);

        return $valid;
    }
}
