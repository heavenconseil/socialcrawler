<?php

namespace SocialCrawler\Channel;

use Guzzle\Http\Client;
use Guzzle\Plugin\Oauth\OAuthPlugin;

class TwitterChannel extends Channel
{
    const SITE_URL = 'https://twitter.com/';
    const API_URL = 'https://api.twitter.com/1.1';
    private $api;

    public function __construct($applicationId, $applicationSecret, $applicationToken = null) {
        $this->api = new Client(self::API_URL);
        $this->api->addSubscriber(new OAuthPlugin(array(
            'consumer_key'      => $applicationId,
            'consumer_secret'   => $applicationSecret,
        )));
    }

    public function fetch($query, $type, $since = null) {
        $options = array('query' => array(
            'q'             => urlencode($query),
            'result_type'   => 'recent',
        ));
        if (isset($since)) {
            $options['query']['since_id'] = $since;
        }
        try {
            $data = $this->api->get('search/tweets.json', array(), $options)->send()->json();
        } catch (\Exception $e) {
            \SocialCrawler\Crawler::log($this, \SocialCrawler\Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        }

        return $this->parse($data, $type);
    }

    protected function parse($data, $type) {
        $results = array();

        // NOTE: Only handles MEDIA_IMAGES for now.
        // TODO: Handle all the media attachments (not only the first one)
        foreach ($data['statuses'] as $entry) {
            if (empty($entry['entities']) || empty($entry['entities']['media']) || count($entry['entities']['media']) === 0 || $entry['entities']['media'][0]['type'] !== 'photo') {
                continue;
            }
            $result = array(
                'id'            => $entry['id_str'],
                'author'        => array(
                    'id'        => $entry['user']['id_str'],
                    'avatar'    => str_replace('_normal', '', $entry['user']['profile_image_url_https']),
                    'fullname'  => $entry['user']['name'],
                    'username'  => $entry['user']['screen_name'],
                ),
                'description'   => $entry['text'],
                'link'          => self::SITE_URL . $entry['user']['screen_name'] . '/status/' . $entry['id_str'],
                'source'        => $entry['entities']['media'][0]['media_url_https'] . ':large',
                'thumbnail'     => $entry['entities']['media'][0]['media_url_https'] . ':small',
                'type'          => 'image',
                'raw'           => $entry,
            );

            $results[] = $result;
        }

        return json_decode(json_encode(array(
            'new_since' => $data['search_metadata']['max_id_str'],
            'data'      => $results,
        )));
    }
}
