<?php

namespace SocialCrawler\Channel;

use Guzzle\Http\Client,
    Guzzle\Plugin\Oauth\OauthPlugin,
    \SocialCrawler\Crawler,
    \Exception,
    \stdClass;

class TwitterChannel extends Channel
{
    const SITE_URL        = 'https://twitter.com/';
    const API_URL         = 'https://api.twitter.com/1.1';

    const TYPE_PHOTO      = 'photo';

    const ENDPOINT_SEARCH = 'search/tweets.json';
    const ENDPOINT_USER   = 'users/show.json';

    private $api;

    private static function _parseAll(stdClass $pEntry) {
        $data = array();

        $images = self::_parseImages($pEntry);
        $data   = array_merge($data, $images);

        return $data;
    }

    private static function _parseImages(stdClass $pEntry) {
        if (isset($pEntry->entities->media)
            and count($pEntry->entities->media) > 0
            and $pEntry->entities->media[0]->type === self::TYPE_PHOTO) {
            $media = $pEntry->entities->media[0];

            return array(
                'source' => $media->media_url . ':large',
                'thumb'  => $media->media_url . ':small',
                'type'   => Channel::TYPE_IMAGE
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
        if (strpos($query, 'user:') === 0) {
            $options = array(
                'query' => array(
                    'screen_name'      => substr($query, 5),
                    'include_entities' => 'false'
                )
            );

            $endpoint = self::ENDPOINT_USER;
        } else if (strpos($query, 'from:') === 0) {
            $options = array(
                'query' => array(
                    'q'           => str_replace('from:', 'from:@', $query),
                    'result_type' => 'recent'
                )
            );

            if (isset($since)) {
                $options['query']['since_id'] = $since;
            }

            $endpoint = self::ENDPOINT_SEARCH;
        } else {
            $options = array(
                'query' => array(
                    'q'           => urlencode($query),
                    'result_type' => 'recent'
                )
            );

            if (isset($since)) {
                $options['query']['since_id'] = $since;
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

    protected function parse(stdClass $data, $type) {
        $return = new stdClass;
        $return->data = array();

        // NOTE: Only handles MEDIA_IMAGES for now.
        // TODO: Handle all the media attachments (not only the first one)
        switch ($type) {
            case Channel::MEDIA_IMAGES:
                $parseType = '_parseImages';
                break;
            case Channel::MEDIA_TEXT:
            case Channel::MEDIA_ALL:
                $parseType = NULL;
                break;
            case Channel::MEDIA_VIDEOS:
            case Channel::MEDIA_IMAGES_VIDEOS:
                return $return;
        }

        if (isset($data->statuses)) {
            $results = array();

            foreach ($data->statuses as $entry) {
                if (! preg_match('/^RT @/is', $entry->text)) {
                    if (isset($parseType)) {
                        $partialData = self::$parseType($entry);
                    } else {
                        $partialData = self::_parseAll($entry);
                    }

                    if (! isset($parseType) or ! empty($partialData)) {
                        $result                   = new stdClass;
                        $result->id               = $entry->id_str;
                        $result->created_at       = date('Y-m-d H:i:s', strtotime($entry->created_at));
                        $result->description      = $entry->text;
                        $result->link             = self::SITE_URL . $entry->user->screen_name . '/status/' . $entry->id_str;
                        $result->type             = Channel::TYPE_TEXT;

                        $result->author           = new stdClass;
                        $result->author->id       = $entry->user->id_str;
                        $result->author->avatar   = str_replace('_normal', '', $entry->user->profile_image_url_https);
                        $result->author->fullname = $entry->user->name;
                        $result->author->username = $entry->user->screen_name;

                        $result->type             = Channel::TYPE_TEXT;

                        $result = (object)array_merge((array)$result, (array)$partialData);

                        $result->raw = $entry;

                        $results[] = $result;
                    }
                }
            }

            $return->new_since = $data->search_metadata->max_id_str;
            $return->data      = $results;
        } else if (isset($data->id_str)) {
            $return->data = new stdClass;

            $return->data->id       = $data->id_str;
            $return->data->fullname = $data->name;
            $return->data->username = $data->screen_name;
            $return->data->image    = $data->profile_image_url;
            $return->data->raw      = $data;
        }

        return $return;
    }
}
