<?php

namespace SocialCrawler\Channel;

use Guzzle\Http\Client;

class InstagramChannel extends Channel
{
    const API_URL = 'https://api.instagram.com/v1/';
    private $api;
    private $client_id;

    public function __construct($applicationId, $applicationSecret = null, $applicationToken = null) {
        $this->api = new Client(self::API_URL);
        $this->client_id = $applicationId;
    }

    public function fetch($query, $type, $since = null) {
        $options = array('query' => array(
            'client_id' => $this->client_id,
        ));
        if (isset($since)) {
            $options['query']['max_tag_id'] = $since;
        }
        try {
            $data = $this->api->get('tags/' . $this->sanitize($query) . '/media/recent', array(), $options)->send()->json();
        } catch (\Exception $e) {
            \SocialCrawler\Crawler::log($this, \SocialCrawler\Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        }

        return $this->parse($data, $type);
    }

    protected function parse($data, $type) {
        $results = array();

        foreach ($data['data'] as $entry) {
            if (
                $type === self::MEDIA_IMAGES && $entry['type'] === 'image' ||
                $type === self::MEDIA_VIDEOS && $entry['type'] === 'video' ||
                $type === self::MEDIA_IMAGES_VIDEOS
            ) {
                $result = array(
                    'id'            => $entry['id'],
                    'author'        => array(
                        'id'        => $entry['user']['id'],
                        'avatar'    => $entry['user']['profile_picture'],
                        'fullname'  => $entry['user']['full_name'],
                        'username'  => $entry['user']['username'],
                    ),
                    'description'   => isset($entry['caption']['text']) ? $entry['caption']['text'] : '',
                    'type'          => $entry['type'],
                    'raw'           => $entry,
                    'thumbnail'     => $entry['images']['low_resolution']['url'],
                );

                if ($entry['type'] === 'image') {
                    $result['source'] = $entry['images']['standard_resolution']['url'];
                } elseif ($entry['type'] === 'video') {
                    $result['source'] = $entry['videos']['standard_resolution']['url'];
                }

                $results[] = $result;
            }
        }

        return json_decode(json_encode(array(
            'new_since' => $data['pagination']['min_tag_id'],
            'data'      => $results,
        )));
    }

    private function sanitize($data) {
        return preg_replace('/[^\w-]/', '', $data);
    }
}
