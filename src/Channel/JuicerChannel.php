<?php
namespace SocialCrawler\Channel;

use Guzzle\Http\Client,
    \Guzzle\Http\Exception\ClientErrorResponseException,
    \SocialCrawler\Crawler,
    \Exception,
    \stdClass,
    \DateTime,
    \DateInterval;

class JuicerChannel extends Channel
{
    const API_URL                = 'https://www.juicer.io/api/feeds/';

    const ENDPOINT_SEARCH        = '%s';

    const RESULTS_PER_PAGE       = 100;  // max. 100

    const TYPE_IMAGE             = 'image';
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
        $type = self::TYPE_IMAGE;
        if (isset($pEntry->{$type})
            and !empty($pEntry->{$type})
            and (strpos($pEntry->{$type}, 'safe_image.php') === false)) {
            return array(
                'source' => $pEntry->{$type},
                'thumb'  => $pEntry->{$type},
                'type'   => Channel::TYPE_IMAGE
            );
        }

        return array();
    }

    private static function _parseVideos(stdClass $pEntry)
    {
        $type = self::TYPE_VIDEO;
        if (isset($pEntry->{$type})
            and !empty($pEntry->{$type})) {
            return array(
                'source' => $pEntry->{$type},
                'thumb'  => $pEntry->{$type},
                'type'   => Channel::TYPE_VIDEO
            );
        }

        return array();
    }

    public function __construct($applicationId, $applicationSecret = null, $applicationToken = null, $params = null)
    {
        $this->api     = new Client(self::API_URL);
        $this->api_key = $applicationId;
        $this->params  = $params;
    }

    public function fetch($query, $type, $since = null, $pIncludeRaw = false, $pageNum = 1)
    {
        $since = $this->decodeSince($since, $query, true);

        if (strpos($query, 'user:') === 0) {
            throw new Exception("'user:' not supported for {get_class()}");
        } else if (strpos($query, 'from:') === 0) {
            throw new Exception("'from:' not supported for {get_class()}");
        } else {
            $options = array(
                'query' => array(
                    'per' => self::RESULTS_PER_PAGE,
                    'page' => $pageNum
                )
            );
            if (isset($since)) {
                $options['query']['starting_at'] = $since->format('Y-m-d H:i');
            }
            if (isset($this->params)) {
                if (isset($this->params['filter'])) {
                    $options['query']['filter'] = $this->params['filter'];
                }
            }
            $endpoint = sprintf(self::ENDPOINT_SEARCH, $this->api_key);
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

        if (isset($return->original_data_count) && ($return->original_data_count >= self::RESULTS_PER_PAGE)) {
            $newData = $this->fetch($query, $type, $since, $pIncludeRaw, $pageNum + 1);
            $return = $this->handleNewPage($return, $newData);
        }

        if ($pageNum === 1) {
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

        if (isset($data->posts->items) and is_array($data->posts->items)) {
            $results = array();

            foreach ($data->posts->items as $entry) {
                if (isset($parseType)) {
                    if ($parseType === '_parseText'
                        and (! isset($entry->unformatted_message)
                        or empty($entry->unformatted_message))) {
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
                    $result->id               = $entry->external_id;
                    $result->created_at       = date('Y-m-d H:i:s', strtotime($entry->external_created_at));
                    $result->created_at_orig  = $entry->external_created_at;
                    $result->description      = (isset($entry->unformatted_message)) ? $entry->unformatted_message : '';
                    $result->link             = $entry->full_url;
                    $result->type             = Channel::TYPE_TEXT;

                    $result->author           = new stdClass;
                    $result->author->id       = $entry->poster_id;

                    if (isset($entry->poster_image)) {
                        $result->author->avatar  = $entry->poster_image;
                    } else {
                        $result->author->avatar  = '';
                    }

                    $result->author->fullname = '';

                    $url                      = $entry->poster_url;
                    $result->author->username = $entry->poster_name;

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
