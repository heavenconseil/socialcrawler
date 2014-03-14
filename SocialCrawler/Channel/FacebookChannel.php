<?php

namespace SocialCrawler\Channel;

use Guzzle\Http\Client,
    \SocialCrawler\Crawler,
    \stdClass;

class FacebookChannel extends Channel
{
    const API_URL            = 'https://graph.facebook.com/';

    const ENDPOINT_USER_FEED = '%s/feed/';
    const ENDPOINT_USER      = '%s';
    //const ENDPOINT_USER_PIC  = '%s/picture';
    //const ENDPOINT_IMAGE     = '%s/?fields=images';
    const ENDPOINT_SEARCH    = 'search';

    private $api;
    private $token;

    public function __construct($applicationId = null, $applicationSecret = null, $applicationToken) {
        $this->api   = new Client(self::API_URL);
        $this->token = $applicationToken;
    }

    public function fetch($query, $type, $since = null) {
        if (strpos($query, 'user:') === 0) {
            $options = array();

            $endpoint = sprintf(self::ENDPOINT_USER, substr($query, 5));
        } else if (strpos($query, 'from:') === 0) {
            $options = array(
                'query' => array(
                    'access_token' => $this->token
                )
            );

            if (isset($since)) {
                $options['query']['since'] = $since;
            }

            $endpoint = sprintf(self::ENDPOINT_USER_FEED, substr($query, 5));
        } else {
            $options = array(
                'query' => array(
                    'q'            => $query,
                    'access_token' => $this->token
                )
            );

            if (isset($since)) {
                $options['query']['since'] = $since;
            }

            $endpoint = self::ENDPOINT_SEARCH;
        }

        try {
            $data = static::decodeBody($this->api->get($endpoint, array(), $options)->send());
        } catch (Exception $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        }

        return $this->parse($data, $type);
    }
}
