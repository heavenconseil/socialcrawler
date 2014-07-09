<?php
namespace SocialCrawler\Channel;

use Guzzle\Http\Client,
    \Guzzle\Http\Exception\ClientErrorResponseException,
    \SocialCrawler\Crawler,
    \Exception,
    \stdClass;

class InstagramChannel extends Channel
{
    const API_URL             = 'https://api.instagram.com/v1/';

    const ENDPOINT_TAG        = 'tags/%s/media/recent/';
    const ENDPOINT_USER_MEDIA = 'users/%s/media/recent/';
    const ENDPOINT_USER       = 'users/%s/';

    const TYPE_IMAGE          = 'image';
    const TYPE_VIDEO          = 'video';

    private $api;
    private $client_id;
    private $token;

    public static function sanitize($pData)
    {
        return preg_replace('/[^\w-]/', '', $pData);
    }

    private static function _parseAll(stdClass $pEntry)
    {
        $data   = array();

        $images = self::_parseImages($pEntry);
        $data   = array_merge($data, $images);

        $videos = self::_parseVideos($pEntry);
        $data   = array_merge($data, $videos);

        return $data;
    }

    private static function _parseImages(stdClass $pEntry)
    {
        if ($pEntry->type === self::TYPE_IMAGE) {
            return array(
                'source' => $pEntry->images->standard_resolution->url,
                'thumb'  => $pEntry->images->low_resolution->url,
                'type'   => Channel::TYPE_IMAGE
            );
        }

        return array();
    }

    private static function _parseVideos(stdClass $pEntry)
    {
        if ($pEntry->type === self::TYPE_VIDEO) {
            return array(
                'source' => $pEntry->videos->standard_resolution->url,
                'thumb'  => $pEntry->images->low_resolution->url,
                'type'   => Channel::TYPE_VIDEO
            );
        }

        return array();
    }

    public function __construct($applicationId, $applicationSecret = null, $applicationToken = null)
    {
        $this->api       = new Client(self::API_URL);
        $this->client_id = $applicationId;
        $this->token     = $applicationToken;
    }

    public function fetch($query, $type, $since = null, $pIncludeRaw = false)
    {
        if (strpos($query, 'user:') === 0) {
            $options = array(
                'query' => array(
                    'client_id' => $this->client_id
                )
            );

            $endpoint = sprintf(self::ENDPOINT_USER, self::sanitize(substr($query, 5)));
        } else if (strpos($query, 'from:') === 0) {
            $options = array(
                'query' => array(
                    'access_token' => $this->token
                )
            );

            if (isset($since)) {
                $options['query']['min_id'] = $since;
            }

            $endpoint = sprintf(self::ENDPOINT_USER_MEDIA, self::sanitize(substr($query, 5)));
        } else {
            if (strpos($query, '#') === 0) {
                $query = substr($query, 1);
            }

            $options = array(
                'query' => array(
                    'client_id' => $this->client_id
                )
            );

            if (isset($since)) {
                $options['query']['max_tag_id'] = $since;
            }

            $endpoint = sprintf(self::ENDPOINT_TAG, self::sanitize($query));
        }

        try {
            $data = static::decodeBody($this->api->get($endpoint, array(), $options)->send());
        } catch (Exception $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        } catch (ClientErrorResponseException $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        }

        return $this->parse($data, $type, $pIncludeRaw);
    }

    protected function parse(stdClass $data, $type, $pIncludeRaw = false)
    {
        $return  = new stdClass;
        $return->data = array();

        switch ($type) {
            case Channel::MEDIA_IMAGES:
                $parseType = '_parseImages';
                break;
            case Channel::MEDIA_VIDEOS:
                $parseType = '_parseVideos';
                break;
            case Channel::MEDIA_IMAGES_VIDEOS:
            case Channel::MEDIA_ALL:
                $parseType = NULL;
                break;
            case Channel::MEDIA_TEXT:
                return $return;
        }

        if (isset($data->data) and is_array($data->data)) {
            $results = array();
            $minId   = NULL;

            foreach ($data->data as $entry) {
                if (isset($parseType)) {
                    $partialData = self::$parseType($entry);
                } else {
                    $partialData = self::_parseAll($entry);
                }

                if (! isset($parseType) or ! empty($partialData)) {
                    if (! $minId) {
                        $minId = $entry->id;
                    }

                    $result                   = new stdClass;
                    $result->id               = $entry->id;
                    $result->created_at       = date('Y-m-d H:i:s', $entry->created_time);
                    $result->description      = (isset($entry->caption->text)) ? $entry->caption->text : '';
                    $result->link             = $entry->link;

                    $result->author           = new stdClass;
                    $result->author->id       = $entry->user->id;
                    $result->author->avatar   = $entry->user->profile_picture;
                    $result->author->fullname = $entry->user->full_name;
                    $result->author->username = $entry->user->username;

                    $result->thumb            = '';
                    $result->type             = '';
                    $result->source           = '';

                    $result = (object)array_merge((array)$result, (array)$partialData);

                    if ($pIncludeRaw) {
                        $result->raw = $entry;
                    }

                    $results[] = $result;
                }
            }

            if (isset($data->pagination->next_max_tag_id)) {
                $return->new_since = $data->pagination->next_max_tag_id;
            } else {
                $return->new_since = $minId;
            }

            $return->data = $results;
        } else if (isset($data->data->id)) {
            $return->data = new stdClass;

            $return->data->id       = $data->data->id;
            $return->data->fullname = $data->data->full_name;
            $return->data->username = $data->data->username;

            if (isset($data->data->profile_picture)) {
                $return->data->avatar = $data->data->profile_picture;
            } else {
                $return->data->avatar = '';
            }

            if ($pIncludeRaw) {
                $return->data->raw  = $data;
            }
        }

        return $return;
    }
}
