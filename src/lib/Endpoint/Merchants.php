<?php declare(strict_types=1);

namespace Lunar\Payment\lib\Endpoint;

use Lunar\Payment\lib\Endpoint\Merchant\Apps as MerchantApps;
use Lunar\Payment\lib\Endpoint\Merchant\Lines;
use Lunar\Payment\lib\Utils\Cursor;

/**
 * Class Merchants
 *
 *
 */
class Merchants extends Endpoint
{
    /**
     * @param $args array
     * @return string
     */
    public function create($args)
    {
        $url = 'merchants';

        $api_response = $this->endpoint->client->request('POST', $url, $args);

        return $api_response->json['merchant']['id'];
    }

    /**
     * @param $merchant_id
     * @return mixed
     */
    public function fetch($merchant_id)
    {
        $url = 'merchants/' . $merchant_id;

        $api_response = $this->endpoint->client->request('GET', $url);

        return $api_response->json['merchant'];
    }

    /**
     * @param $merchant_id
     * @param $args
     *
     * @return void
     */
    public function update($merchant_id, $args)
    {
        $url = 'merchants/' . $merchant_id;

        return $this->endpoint->client->request('PUT', $url, $args);
    }

    /**
     * @param $app_id
     * @param array $args
     * @return Cursor
     * @throws \Exception
     */
    public function find($app_id, $args = array())
    {
        $url = 'identities/' . $app_id . '/merchants';
        if (!isset($args['limit'])) {
            $args['limit'] = 10;
        }
        $api_response = $this->endpoint->client->request('GET', $url, $args);
        $merchants = $api_response->json;
        return new Cursor($url, $args, $merchants, $this->endpoint);
    }

    /**
     * @param $app_id
     * @param $merchant_id
     * @return Cursor
     * @throws \Exception
     */
    public function before($app_id, $merchant_id)
    {
        return $this->find($app_id, array('before' => $merchant_id));
    }

    /**
     * @param $app_id
     * @param $merchant_id
     * @return Cursor
     * @throws \Exception
     */
    public function after($app_id, $merchant_id)
    {
        return $this->find($app_id, array('after' => $merchant_id));
    }

    /**
     * @return Lines
     */
    public function lines()
    {
        return new Lines($this->endpoint);
    }

    /**
     * @return Lines
     */
    public function apps()
    {
        return new MerchantApps($this->endpoint);
    }
}
