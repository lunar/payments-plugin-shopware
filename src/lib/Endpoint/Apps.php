<?php declare(strict_types=1);

namespace Lunar\Payment\lib\Endpoint;

/**
 * Class Apps
 *
 *
 */
class Apps extends Endpoint
{
    /**
     * @param $args array
     *
     * @return string
     */
    public function create($args)
    {
        $url = 'apps';

        $api_response = $this->endpoint->client->request('POST', $url, $args);

        return $api_response->json['app'];
    }

    /**
     * @return array
     */
    public function fetch()
    {
        $url = 'me';

        $api_response = $this->endpoint->client->request('GET', $url);

        return $api_response->json['identity'];
    }

}
