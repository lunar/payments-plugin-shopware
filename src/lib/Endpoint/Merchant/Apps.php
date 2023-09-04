<?php declare(strict_types=1);

namespace Lunar\Payment\lib\Endpoint\Merchant;

use Lunar\Payment\lib\Endpoint\Endpoint;
use Lunar\Payment\lib\Utils\Cursor;

/**
 * Class Apps
 *
 *
 */
class Apps extends Endpoint
{

    /**
     *
     *
     * @param $merchant_id
     * @param array $args
     * @return Cursor
     * @throws \Exception
     */
    public function find($merchant_id, $args = array())
    {
        $url = 'merchants/' . $merchant_id . '/apps';
        if (!isset($args['limit'])) {
            $args['limit'] = 10;
        }
        $api_response = $this->endpoint->client->request('GET', $url, $args);
        $apps = $api_response->json;
        return new Cursor($url, $args, $apps, $this->endpoint);
    }

    /**
     *
     *
     * @param $args array
     *
     * @return string
     */
    public function add($merchant_id, $app_id)
    {
        $url = 'merchants/' . $merchant_id . '/apps';

        $args = array(
            'appId' => $app_id
        );

        $api_response = $this->endpoint->client->request('POST', $url, $args);

        return $api_response;
    }

    /**
     *
     *
     * @param $args array
     *
     * @return string
     */
    public function revoke($merchant_id, $app_id)
    {
        $url = 'merchants/' . $merchant_id . '/apps/'.$app_id;


        $api_response = $this->endpoint->client->request('DELETE', $url);

        return $api_response;
    }

}
