<?php

namespace SocialCrawler\Channel;

use \Guzzle\Http\Client,
    \Guzzle\Http\Exception\ClientErrorResponseException,
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

    const TYPE_IMAGE         = 'photo';
    const TYPE_VIDEO         = 'video';

    private $api;
    private $token;

    private static function getThumb(stdClass $pEntry) {
        if (strpos($pEntry->picture, 'safe_image.php') === false) {
            return $pEntry->picture;
        }

        preg_match('/url=(.*)/', $pEntry->picture, $matches);
        return urldecode($matches[1]);
    }

    public function __construct($applicationId = null, $applicationSecret = null, $applicationToken) {
        $this->api   = new Client(self::API_URL);
        $this->token = $applicationToken;
    }

    private function _requestGraph($pEndpoint, array $pOptions) {
        try {
            $data = static::decodeBody($this->api->get($pEndpoint, array(), $pOptions)->send());
        } catch (Exception $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        } catch (ClientErrorResponseException $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        }

        return $data;
    }

    private function _getUsername($pId) {
        $options  = array();
        $endpoint = sprintf(self::ENDPOINT_USER, $pId);
        $data     = $this->_requestGraph($endpoint, $options);
        if ($data instanceof stdClass and isset($data->username)) {
            return $data->username;
        }

        return '';
    }

    private function _parseAll(stdClass $pEntry) {
        $data = array();

        $images = $this->_parseImages($pEntry);
        $data   = array_merge($data, $images);

        $videos = $this->_parseVideos($pEntry);
        $data   = array_merge($data, $videos);

        return $data;
    }

    private function _parseImagesVideos(stdClass $pEntry) {
        return $this->_parseAll($pEntry);
    }

    private function _parseImages(stdClass $pEntry) {
        if ($pEntry->type === self::TYPE_IMAGE
            and isset($pEntry->object_id)
            and ! empty($pEntry->object_id)
            and isset($pEntry->picture)
            and ! empty($pEntry->picture)) {
            $options  = array();
            $endpoint = sprintf(self::ENDPOINT_USER, $pEntry->object_id);
            $data     = $this->_requestGraph($endpoint, $options);

            if (isset($data) and isset($data->images)) {
                $bestImage = array('id' => 0, 'width' => 0);
                $nbImages  = count($data->images);

                for ($i= 0; $i < $nbImages; $i++) {
                    $image = $data->images[$i];
                    if ($image->width > $bestImage['width']) {
                        $bestImage['id'] = $i;
                        $bestImage['width'] = $image->width;
                    }
                }

                return array(
                    'source' => $data->images[$bestImage['id']]->source,
                    'thumb'  => self::getThumb($pEntry),
                    'type'   => Channel::TYPE_IMAGE
                );
            }
        }

        return array();
    }

    private function _parseVideos(stdClass $pEntry) {
        if ($pEntry->type === self::TYPE_VIDEO
            and isset($pEntry->picture)
            and ! empty($pEntry->picture)
            and isset($pEntry->source)
            and ! empty($pEntry->source)) {

            return array(
                'source' => $pEntry->source,
                'thumb'  => self::getThumb($pEntry),
                'type'   => Channel::TYPE_VIDEO
            );
        }

        return array();
    }

    public function fetch($query, $type, $since = null, $pIncludeRaw) {
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

        $data = $this->_requestGraph($endpoint, $options);

        return $this->parse($data, $type, $pIncludeRaw);
    }

    protected function parse(stdClass $data, $type, $pIncludeRaw) {
        $return  = new stdClass;
        $return->data = array();

        switch ($type) {
            case Channel::MEDIA_IMAGES:
                $parseType = '_parseImages';
                break;
            case Channel::MEDIA_VIDEOS:
                $parseType = '_parseVideos';
                break;
            case Channel::MEDIA_TEXT:
                $parseType = '_parseText';
                break;
            case Channel::MEDIA_IMAGES_VIDEOS:
                $parseType = '_parseImagesVideos';
                break;
            case Channel::MEDIA_ALL:
                $parseType = NULL;
                break;
        }

        if (isset($data->data) and is_array($data->data)) {
            $results = array();
            $minId   = NULL;

            foreach ($data->data as $entry) {
                if (isset($parseType)) {
                    if ($parseType === '_parseText'
                        and empty($entry->message)) {
                        continue;
                    }

                    if ($parseType === '_parseText') {
                        $partialData = array();
                    } else {
                        $partialData = $this->$parseType($entry);
                    }
                } else {
                    $partialData = $this->_parseAll($entry);
                }

                if ($parseType === '_parseText' or ! isset($parseType) or ! empty($partialData)) {
                    if (! $minId) {
                        $minId = $entry->id;
                    }

                    $result                   = new stdClass;
                    $result->id               = $entry->id;
                    $result->created_at       = date('Y-m-d H:i:s', strtotime($entry->created_time));
                    $result->description      = isset($entry->message) ? $entry->message : '';
                    $result->link             = 'http://www.facebook.com/' . $entry->id;

                    $result->author           = new stdClass;
                    $result->author->id       = $entry->from->id;
                    $result->author->avatar   = self::API_URL . $entry->from->id . '/picture?type=large';
                    $result->author->fullname = $entry->from->name;
                    $result->author->username = $this->_getUsername($entry->from->id);

                    $result->type             = Channel::TYPE_TEXT;
                    $result->thumb            = '';
                    $result->source           = '';

                    $result = (object)array_merge((array)$result, (array)$partialData);

                    if ($pIncludeRaw) {
                        $result->raw = $entry;
                    }

                    if ($result->type === Channel::TYPE_TEXT and ! $result->description) {
                        continue;
                    }

                    $results[] = $result;
                }
            }

            $return->new_since = $minId;

            $return->data = $results;
        } else if (isset($data->id)) {
            $return->data = new stdClass;

            $return->data->id       = $data->id;
            $return->data->fullname = $data->name;
            $return->data->username = $data->username;
            $return->data->avatar   = self::API_URL . $data->id . '/picture?type=large';

            if ($pIncludeRaw) {
                $return->data->raw = $data;
            }
        }

        return $return;
    }
}
