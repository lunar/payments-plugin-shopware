<?php declare(strict_types=1);

namespace Lunar\Payment\lib;

use Lunar\Payment\lib\HttpClient\HttpClientInterface;
use Lunar\Payment\lib\HttpClient\CurlClient;
use Lunar\Payment\lib\Endpoint\Apps;
use Lunar\Payment\lib\Endpoint\Merchants;
use Lunar\Payment\lib\Endpoint\Transactions;

/**
 *
 */
class ApiClient
{
    /**
     * @var string
     */
    const API_URL = 'https://api.paylike.io';

    /**
     * @var HttpClientInterface
     */
    public $client;

    /**
     * @var string
     */
    private $api_key;

    private $version = '2.0.0';


    /**
     *
     * @param string $api_key
     * @param HttpClientInterface $client
     * @throws Exception\ApiException
     */
    public function __construct($api_key, HttpClientInterface $client = null)
    {
        $this->api_key = $api_key;
        $this->client  = $client ? $client
            : new CurlClient($this->api_key, self::API_URL);
    }

    /**
     * @return string
     */
    public function getApiKey()
    {
        return $this->api_key;
    }


    /**
     * @return Apps
     */
    public function apps()
    {
        return new Apps($this);
    }

    /**
     * @return Merchants
     */
    public function merchants()
    {
        return new Merchants($this);
    }

    /**
     * @return Transactions
     */
    public function transactions()
    {
        return new Transactions($this);
    }

    /**
     * @return string
     */
    public function getVersion(){
        return $this->version;
    }
}
