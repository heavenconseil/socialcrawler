<?php
namespace SocialCrawler\Channel;

use Guzzle\Http\Client,
    \Guzzle\Http\Exception\ClientErrorResponseException,
    \SocialCrawler\Crawler,
    \Exception,
    \stdClass,
    \DateTime;

class YoutubeChannel extends Channel
{
    const SITE_URL            = 'https://www.youtube.com/watch';
    const API_URL             = 'https://www.googleapis.com/youtube/v3/';

    const RESULTS_PER_PAGE    = 50; // max. 50

    const ENDPOINT_SEARCH     = 'search';
    const ENDPOINT_USER       = 'channels';
    const ENDPOINT_SEARCH_USER = 'playlistItems';

    const TYPE_VIDEO          = 'video';

    private $api;

    public function __construct($applicationId, $applicationSecret = null, $applicationToken = null, $params = null)
    {
        $this->api = new Client(self::API_URL);
        $this->api_key = $applicationId;
    }

    private function _getAuthor($authorId)
    {
        $options = array(
            'query' => array(
                'part' => 'id,snippet,contentDetails',
                'key'  => $this->api_key,
                'id'   => $authorId
            )
        );

        $endpoint = self::ENDPOINT_USER;

        try {
            $data = static::decodeBody($this->api->get($endpoint, array(), $options)->send());
        } catch (Exception $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        }

        return $data;
    }

    public function fetch($query, $type, $since = null, $pIncludeRaw = false, $nextPageToken = null)
    {
        $since = $this->decodeSince($since, $query, true);

        $options = array(
            'query' => array(
                'part' => 'id,snippet',
                'key' => $this->api_key,
                'maxResults' => self::RESULTS_PER_PAGE
            )
        );
        if (isset($nextPageToken)) {
            $options['query']['pageToken'] = $nextPageToken;
        }

        if (strpos($query, 'user:') === 0) {
            $authorId = substr($query, 5);
            $options['query']['id'] = $authorId;
            $endpoint = self::ENDPOINT_USER;
        } else if (strpos($query, 'from:') === 0) {
            $authorId = substr($query, 5);
            $author = $this->_getAuthor($authorId);
            if (isset($author->items[0])) {
                $author = $author->items[0];
                $options['query']['playlistId'] = $author->contentDetails->relatedPlaylists->uploads;
            }

            $endpoint = self::ENDPOINT_SEARCH_USER;
        } else {
            $options['query']['q'] = '#' . $query;
            $options['query']['order'] = 'relevance';   // order=date does not work with hashtags
            if (isset($since)) {
                $options['query']['publishedAfter'] = $since->format(self::ISO_8601_FORMAT);
            }
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
        $return  = new stdClass;
        $return->data = array();

        if (isset($data->kind) && (in_array($data->kind, ['youtube#searchListResponse', 'youtube#playlistItemListResponse'])) && isset($data->items) and is_array($data->items)) {
            $results = array();

            foreach ($data->items as $entry) {
                $result              = new stdClass;

                $id = null;
                if (isset($entry->id->videoId)) {
                    $id = $entry->id->videoId;
                } else if (isset($entry->id->channelId)) {
                    $id = $entry->id->channelId;
                }

                $result->id = $id;
                $snippet = $entry->snippet;

                $result->created_at  = date('Y-m-d H:i:s', strtotime($snippet->publishedAt));
                $result->created_at_orig  = $snippet->publishedAt;
                $result->description = isset($snippet->description) ? $snippet->description : '';
                $result->link        = $id !== null ? (self::SITE_URL . '?v=' . $id) : '';

                $author = $this->_getAuthor($entry->snippet->channelId);
                if (isset($author->items[0])) {
                    $author = $author->items[0];
                    $snippet = $author->snippet;
                    $result->author           = new stdClass;

                    $result->author->id       = $author->id;

                    $result->author->avatar   = isset($snippet->thumbnails->high->url) ? $snippet->thumbnails->high->url : '';
                    $result->author->fullname = $snippet->title;
                    $result->author->username = $result->author->fullname;
                }

                $result->thumb  = $snippet->thumbnails->default->url;
                $result->source  = $snippet->thumbnails->high->url;
                $result->type   = Channel::TYPE_VIDEO;

                if ($pIncludeRaw) {
                    $result->raw = $entry;
                }

                $results[] = $result;
            }

            $return->data = $results;

            $return = $this->removeOldEntries($return, $since);

        } else if (isset($data->kind) && ($data->kind === 'youtube#channelListResponse')) {
            $return->data = new stdClass;

            foreach ($data->items as $entry) {
                $snippet = $entry->snippet;
                $return->data->id       = $entry->id;

                $return->data->fullname = $snippet->title;
                $return->data->username = $return->data->fullname;
                $return->data->avatar   = isset($snippet->thumbnails->high->url) ? $snippet->thumbnails->high->url : '';

                if ($pIncludeRaw) {
                    $return->data->raw = $data;
                }
            }
        }

        return $return;
    }
}
