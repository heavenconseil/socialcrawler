<?php

namespace SocialCrawler\Channel;

abstract class Channel
{
    /**
     * Media types supported
     */
    const MEDIA_IMAGES_VIDEOS   = 'images+videos';
    const MEDIA_IMAGES          = 'images';
    const MEDIA_VIDEOS          = 'videos';

    /**
     * Initializes a Channel
     *
     * @param   string $applicationId     The Channel API's Application ID/Client ID/whatever name they use
     * @param   string $applicationSecret The Channel API's Application Secret, if needed
     * @param   string $applicationToken  Some API require the use of a client access_token
     */
    public function __construct($applicationId, $applicationSecret = null, $applicationToken = null) { }

    /**
     * Searches for content containing a specific hashtag
     *
     * @param   string $query The keyword, or hashtag used in the search
     * @param   string $type  The type of content that will be kept : MEDIA_IMAGES_VIDEOS | MEDIA_IMAGES | MEDIA_VIDEOS
     * @param   string $since The limit from which contents will be returned (some APIs might not use this)
     *
     * @return  object The parsed data with the API
     */
    public function fetch($query, $type, $since = null) { }
}
