<?php

namespace SocialCrawler\Channel;

use Guzzle\Http\Client,
    Guzzle\Plugin\Oauth\OauthPlugin,
    \SocialCrawler\Crawler,
    \Exception,
    \stdClass;

class TwitterChannel extends Channel
{
    const SITE_URL = 'https://twitter.com/';
    const API_URL  = 'https://api.twitter.com/1.1';
    private $api;

    private static function _parseImages(stdClass $pEntry) {
        if (isset($pEntry->entities->media) and count($pEntry->entities->media) > 0) {
            $media = $pEntry->entities->media[0];

            return array(
                'source' => $media->media_url . ':large',
                'thumb'  => $media->media_url . ':small'
            );
        }

        return array();
    }

    public function __construct($applicationId, $applicationSecret, $applicationToken = null) {
        $this->api = new Client(self::API_URL);
        $this->api->addSubscriber(new OauthPlugin(array(
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
            $data = static::decodeBody($this->api->get('search/tweets.json', array(), $options)->send());
        } catch (\Exception $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        }

        return $this->parse($data, $type);
    }

    protected function parse(stdClass $data, $type) {
        $results = array();

        // NOTE: Only handles MEDIA_IMAGES for now.
        // TODO: Handle all the media attachments (not only the first one)
        switch ($type) {
            case Channel::MEDIA_IMAGES:
                $parseType = '_parseImages';
                break;
            default:
                throw new Exception("Invalid Channel Type");
        }

        foreach ($data->statuses as $entry) {
            if (! preg_match('/^RT @/is', $entry->text)) {
                $partialData = self::$parseType($entry, $results);

                if (! empty($partialData)) {
                    $result = array(
                        'id'           => $entry->id_str,
                        'created_at'   => $entry->created_at,
                        'author'       => array(
                            'id'       => $entry->user->id_str,
                            'avatar'   => str_replace('_normal', '', $entry->user->profile_image_url_https),
                            'fullname' => $entry->user->name,
                            'username' => $entry->user->screen_name,
                        ),
                        'description'  => $entry->text,
                        'link'         => self::SITE_URL . $entry->user->screen_name . '/status/' . $entry->id_str,
                        'type'         => 'image',
                        'raw'          => $entry
                    );

                    $result    = array_merge($result, $partialData);
                    $results[] = $result;
                }
            }
        }

        $return            = new stdClass;
        $return->new_since = $data->search_metadata->max_id_str;
        $return->data      = (object)$results;

        return $return;
    }
}
