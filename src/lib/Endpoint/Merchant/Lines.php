<?php declare(strict_types=1);

namespace Lunar\Payment\lib\Endpoint\Merchant;

use Lunar\Payment\lib\Endpoint\Endpoint;
use Lunar\Payment\lib\Utils\Cursor;

/**
 * Class Lines
 *
 *
 */
class Lines extends Endpoint
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
        $url = 'merchants/' . $merchant_id . '/lines';
        if (!isset($args['limit'])) {
            $args['limit'] = 10;
        }
        $api_response = $this->endpoint->client->request('GET', $url, $args);
        $lines = $api_response->json;
        return new Cursor($url, $args, $lines, $this->endpoint);
    }

    /**
     *
     *
     * @param $merchant_id
     * @param $line_id
     * @return Cursor
     * @throws \Exception
     */
    public function before($merchant_id, $line_id)
    {
        return $this->find($merchant_id, array('before' => $line_id));
    }

    /**
     *
     *
     * @param $merchant_id
     * @param $line_id
     * @return Cursor
     * @throws \Exception
     */
    public function after($merchant_id, $line_id)
    {
        return $this->find($merchant_id, array('after' => $line_id));
    }
}
