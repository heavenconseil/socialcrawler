<?php
/**
 * @todo Upgrade to API V3, implement search
 */

namespace SocialCrawler\Channel;

use Guzzle\Http\Client,
    \SocialCrawler\Crawler,
    \Exception,
    \stdClass;

class YoutubeChannel extends Channel
{
    const API_URL             = 'https://gdata.youtube.com/feeds/api/';

    const ENDPOINT_USER       = 'users/%s';
    //const ENDPOINT_VIDEO      = 'videos/%s?v=2&alt=json';
    const ENDPOINT_USER_VIDEO = 'users/%s/uploads';

    const TYPE_VIDEO          = 'video';

    private $api;

    public function __construct($applicationId = null, $applicationSecret = null, $applicationToken = null) {
        $this->api = new Client(self::API_URL);
    }

    private function _getAuthor(stdClass $pEntry) {
        $options = array(
            'query' => array(
                'alt' => 'json'
            )
        );

        $endpoint = str_replace(self::API_URL, '', $pEntry->author[0]->uri->{'$t'});

        try {
            $data = static::decodeBody($this->api->get($endpoint, array(), $options)->send());
        } catch (Exception $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        }

        return $data;
    }

    public function fetch($query, $type, $since = null, $pIncludeRaw) {
        if (strpos($query, 'user:') === 0) {
            $options = array(
                'query' => array(
                    'alt' => 'json'
                )
            );

            $endpoint = sprintf(self::ENDPOINT_USER, substr($query, 5));
        } else if (strpos($query, 'from:') === 0) {
            $options = array(
                'query' => array(
                    'alt' => 'json'
                )
            );

            $endpoint = sprintf(self::ENDPOINT_USER_VIDEO, substr($query, 5));
        } else {
            $return       = new stdClass;
            $return->data = array();
            return $return;
        }

        try {
            $data = static::decodeBody($this->api->get($endpoint, array(), $options)->send());
        } catch (Exception $e) {
            Crawler::log($this, Crawler::LOG_ERROR, str_replace("\n", ' ', $e->getMessage()));
            return false;
        }

        return $this->parse($data, $type, $pIncludeRaw);
    }

    protected function parse(stdClass $data, $type, $pIncludeRaw) {
        $return  = new stdClass;
        $return->data = array();

        if (isset($data->feed) and isset($data->feed->entry) and is_array($data->feed->entry)) {
            $results = array();

            foreach ($data->feed->entry as $entry) {
                $result              = new stdClass;

                $id                  = $entry->id->{'$t'};
                $idArr               = explode('/', $id);
                $result->id          = end($idArr);

                $result->created_at  = date('Y-m-d H:i:s', strtotime($entry->published->{'$t'}));
                $result->description = (isset($entry->content->{'$t'})) ? $entry->content->{'$t'} : '';
                $result->link        = $entry->link[0]->href;

                $author = $this->_getAuthor($entry);
                if (isset($author->entry->id)) {
                    $result->author           = new stdClass;

                    $id                       = $author->entry->id->{'$t'};
                    $idArr                    = explode('/', $id);
                    $result->author->id       = end($idArr);

                    $result->author->avatar   = $author->entry->{'media$thumbnail'}->url;
                    $result->author->fullname = $author->entry->title->{'$t'};
                    $result->author->username = $author->entry->{'yt$username'}->{'$t'};
                }

                $result->thumb  = $entry->{'media$group'}->{'media$thumbnail'}[0]->url;
                $result->source = '';
                $result->type   = Channel::TYPE_VIDEO;

                if ($pIncludeRaw) {
                    $result->raw = $entry;
                }

                $results[] = $result;
            }

            $return->new_since = 0;

            $return->data = $results;
        } else if (isset($data->entry->id)) {
            $return->data = new stdClass;

            $id                     = $data->entry->id->{'$t'};
            $idArr                  = explode('/', $id);
            $return->data->id       = end($idArr);

            $return->data->fullname = $data->entry->title->{'$t'};
            $return->data->username = $data->entry->{'yt$username'}->{'$t'};
            $return->data->avatar   = $data->entry->{'media$thumbnail'}->url;
            $return->data->raw      = $data;
        }

        return $return;
    }
}
