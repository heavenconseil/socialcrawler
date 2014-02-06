<?php

namespace SocialCrawler\Channel;

use Facebook;
use BaseFacebook;
use Guzzle;

class FacebookChannel extends Channel
{
    private $api;

    public function __construct($applicationId, $applicationSecret, $applicationToken = null) {
        $this->facebook = new Facebook(array(
            'appId'     => $applicationId,
            'secret'    => $applicationSecret,
        ));
        if (isset($applicationToken)) {
            $this->facebook->setAccessToken($applicationToken);
        }

        Guzzle\Http\StaticClient::mount();
    }

    public function fetch($query, $type, $since = null) {
        $parameters = array(
            'q'     => $query,
            'type'  => 'post',
        );
        if (isset($since)) {
            $parameters['since'] = $since;
        }
        try {
            $data = $this->facebook->api('search', $parameters);
        } catch (\Exception $e) {
            \SocialCrawler\Crawler::log($this, \SocialCrawler\Crawler::LOG_ERROR, $e);
            return false;
        }

        return $this->parse($data, $type);
    }

    protected function parse($data, $type) {
        $results = array();

        foreach ($data['data'] as $entry) {
            $media_url = $this->get_media_url($entry);
            if (
                (
                    $type === self::MEDIA_IMAGES && $entry['type'] === 'photo' ||
                    $type === self::MEDIA_VIDEOS && $entry['type'] === 'video' ||
                    ($type === self::MEDIA_IMAGES_VIDEOS && in_array($entry['type'], array('photo', 'video')))
                ) &&
                $media_url !== false
            ) {
                $result = array(
                    'id'            => $entry['id'],
                    'author'        => array(
                        'id'        => $entry['from']['id'],
                        'avatar'    => BaseFacebook::$DOMAIN_MAP['graph'] . $entry['from']['id'] . '/picture?type=large',
                        'fullname'  => $entry['from']['name'],
                        'username'  => $this->get_username($entry['from']['id']),
                    ),
                    'description'   => isset($entry['caption']) ? $entry['caption'] : (isset($entry['description']) ? $entry['description'] : (isset($entry['name']) ? $entry['name'] : '')),
                    'link'          => $entry['link'],
                    'source'        => $media_url,
                    'thumbnail'     => $this->get_media_thumbnail($entry),
                    'type'          => $entry['type'] === 'photo' ? 'image' : 'video',
                    'raw'           => $entry,
                );

                $results[] = $result;
            }
        }

        if (empty($data['paging'])) {
            $new_since = isset($since) ? $since : false;
        } else {
            parse_str(array_pop(explode('?', $data['paging']['previous'])), $pager_query);
            $new_since = $pager_query['since'];
        }
        return json_decode(json_encode(array(
            'new_since' => $new_since,
            'data'      => $results,
        )));
    }

    protected function get_media_url($entry) {
        if ($entry['type'] === 'video' && isset($entry['source'])) {
            return $entry['source'];
        }
        if (empty($entry['object_id'])) {
            return false;
        }

        try {
            $data = Guzzle::get(BaseFacebook::$DOMAIN_MAP['graph'] . $entry['object_id'])->json();
        } catch (\Exception $e) {
            \SocialCrawler\Crawler::log($this, \SocialCrawler\Crawler::LOG_VERBOSE, 'Entry skipped. ' . str_replace("\n", ' ', $e->getMessage()), array('object_id' => $entry['object_id']));
            return false;
        }
        if (isset($data) && empty($data->error)) {
            if ($entry['type'] === 'photo') {
                $best_image = array('id' => 0, 'width' => 0);
                for ($i= 0; $i < count($data['images']); $i++) {
                    $image = $data['images'][$i];
                    if ($image['width'] > $best_image['width']) {
                        $best_image['id'] = $i;
                        $best_image['width'] = $image['width'];
                    }
                }
                return $data['images'][$best_image['id']]['source'];
            }
        }

        return false;
    }

    protected function get_media_thumbnail($entry) {
        if (!strstr($entry['picture'], 'safe_image.php')) {
            return $entry['picture'];
        }
        preg_match('/url=(.*)/', $entry['picture'], $matches);
        return urldecode($matches[1]);
    }

    protected function get_username($userId) {
        $data = $this->facebook->api($userId);

        return isset($data['username']) ? $data['username'] : $data['name'];
    }
}
