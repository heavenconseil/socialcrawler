<?php

namespace SocialCrawler\Channel;

abstract class Channel
{
    const MEDIA_IMAGES_VIDEOS   = 'images+videos';
    const MEDIA_IMAGES          = 'images';
    const MEDIA_VIDEOS          = 'videos';

    public function __construct($applicationId, $applicationSecret = null, $applicationToken = null) { }

    public function fetch($query, $type, $since = null) { }
}
