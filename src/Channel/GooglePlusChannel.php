<?php
namespace SocialCrawler\Channel;

use Guzzle\Http\Client,
    \Guzzle\Http\Exception\ClientErrorResponseException,
    \SocialCrawler\Crawler,
    \Exception,
    \stdClass,
    \DateTime,
    \DateInterval;

class GooglePlusChannel extends Channel
{
    const API_URL                = 'https://www.googleapis.com/plus/v1/';

    const ENDPOINT_SEARCH        = 'activities';
    const ENDPOINT_USER          = 'people/%s';
    const ENDPOINT_SEARCH_USER   = 'people/%s/activities/collection';

    const RESULTS_PER_PAGE       = 20;  // max. 20

    const TYPE_IMAGE             = 'photo';
    const TYPE_VIDEO             = 'video';

    private $api;
    private $api_key;

    private static function _parseAll(stdClass $pEntry)
    {
        $data   = array();

        $images = self::_parseImages($pEntry);
        $data   = array_merge($data, $images);

        $videos = self::_parseVideos($pEntry);
        $data   = array_merge($data, $videos);

        return $data;
    }

    private static function _parseImagesVideos(stdClass $pEntry)
    {
        return self::_parseAll($pEntry);
    }

    private static function _parseImages(stdClass $pEntry)
    {
        if (isset($pEntry->object->attachments[0])
            and isset($pEntry->object->attachments[0]->objectType)
            and $pEntry->object->attachments[0]->objectType == self::TYPE_IMAGE
            and isset($pEntry->object->attachments[0]->image->url)
            and isset($pEntry->object->attachments[0]->fullImage->url)) {
            return array(
                'source' => $pEntry->object->attachments[0]->fullImage->url,
                'thumb'  => $pEntry->object->attachments[0]->image->url,
                'type'   => Channel::TYPE_IMAGE
            );
        }

        return array();
    }

    private static function _parseVideos(stdClass $pEntry)
    {
        if (isset($pEntry->object->attachments[0])
            and isset($pEntry->object->attachments[0]->objectType)
            and $pEntry->object->attachments[0]->objectType == self::TYPE_VIDEO
            and isset($pEntry->object->attachments[0]->image->url)
            and isset($pEntry->object->attachments[0]->embed->url)) {
            return array(
                'source' => $pEntry->object->attachments[0]->embed->url,
                'thumb'  => $pEntry->object->attachments[0]->image->url,
                'type'   => Channel::TYPE_VIDEO
            );
        }

        return array();
    }

    public function __construct($applicationId, $applicationSecret = null, $applicationToken = null, $params = null)
    {
        $this->api     = new Client(self::API_URL);
        $this->api_key = $applicationId;
    }

    public function fetch($query, $type, $since = null, $pIncludeRaw = false, $nextPageToken = null)
    {
        $since = $this->decodeSince($since, $query, true);

        $options = array(
            'query' => array(
                'key' => $this->api_key,
                'maxResults' => self::RESULTS_PER_PAGE
            )
        );
        if (isset($nextPageToken)) {
            $options['query']['pageToken'] = $nextPageToken;
        }
        if (strpos($query, 'user:') === 0) {
            $endpoint = sprintf(self::ENDPOINT_USER, substr($query, 5));
        } else if (strpos($query, 'from:') === 0) {
            $endpoint = sprintf(self::ENDPOINT_SEARCH_USER, substr($query, 5));
        } else {
            $options['query']['query'] = $query;
            $options['query']['orderBy'] = 'recent';
            $endpoint = self::ENDPOINT_SEARCH;
        }

        $endpoint = trim($endpoint);

        try {
            $data = static::decodeBody($this->api->get($endpoint, array(), $options)->send());
        } catch (Exception $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        } catch (ClientErrorResponseException $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        }

        $return = $this->parse($data, $type, $pIncludeRaw, $since);

        if (isset($return->original_data_count) && ($return->original_data_count >= self::RESULTS_PER_PAGE) && isset($data->nextPageToken)) {
            $newData = $this->fetch($query, $type, $since, $pIncludeRaw, $data->nextPageToken);
            $return = $this->handleNewPage($return, $newData);
        }

        if (!isset($nextPageToken) && isset($return->new_since)) {
            $return->new_since = $return->new_since->format(self::ISO_8601_FORMAT);
        }

        return $return;
    }

    protected function parse(stdClass $data, $type, $pIncludeRaw = false, $since = null)
    {
        $return = new stdClass;
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

        if (isset($data->items) and is_array($data->items)) {
            $results = array();

            foreach ($data->items as $entry) {
                if (isset($parseType)) {
                    if ($parseType === '_parseText'
                        and (! isset($entry->object->content)
                        or empty($entry->object->content))) {
                        continue;
                    }

                    if ($parseType === '_parseText') {
                        $partialData = array();
                    } else {
                        $partialData = self::$parseType($entry);
                    }
                } else {
                    $partialData = self::_parseAll($entry);
                }

                if ($parseType === '_parseText' or ! isset($parseType) or ! empty($partialData)) {
                    $result                   = new stdClass;
                    $result->id               = $entry->id;
                    $result->created_at       = date('Y-m-d H:i:s', strtotime($entry->published));
                    $result->created_at_orig  = $entry->published;
                    $result->description      = (isset($entry->object->content)) ? $entry->object->content : '';
                    $result->link             = $entry->url;
                    $result->type             = Channel::TYPE_TEXT;

                    $result->author           = new stdClass;
                    $result->author->id       = $entry->actor->id;

                    if (isset($entry->actor->image->url)) {
                        $result->author->avatar  = $entry->actor->image->url;
                    } else {
                        $result->author->avatar  = '';
                    }

                    $result->author->fullname = $entry->actor->displayName;

                    $url                      = $entry->actor->url;
                    $urlArr                   = explode('/', $url);
                    $result->author->username = end($urlArr);

                    if (ctype_digit($result->author->username)) {
                        $result->author->username = '';
                    }

                    $result->source = '';
                    $result->thumb  = '';

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

            $return->data = $results;
            $return = $this->removeOldEntries($return, $since);

        } else if (isset($data->id)) {
            $return->data = new stdClass;

            $return->data->id       = $data->id;
            $return->data->fullname = $data->displayName;

            $url                    = $data->url;
            $urlArr                 = explode('/', $url);
            $return->data->username = end($urlArr);

            if (isset($data->image->url)) {
                $return->data->avatar = $data->image->url;
            } else {
                $return->data->avatar = '';
            }

            if ($pIncludeRaw) {
                $return->data->raw = $data;
            }
        }

        return $return;
    }
}
