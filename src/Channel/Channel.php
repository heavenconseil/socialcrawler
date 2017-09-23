<?php
namespace SocialCrawler\Channel;

use \Exception,
    \DateTime;

abstract class Channel
{
    const ISO_8601_FORMAT = 'Y-m-d\TH:i:s.uP';  // includes microseconds
    /**
     * Media types supported
     */
    const MEDIA_IMAGES_VIDEOS = 'images+videos';
    const MEDIA_IMAGES        = 'images';
    const MEDIA_VIDEOS        = 'videos';
    const MEDIA_TEXT          = 'text';
    const MEDIA_ALL           = 'all';

    /**
     * Single types
     */
    const TYPE_IMAGE = 'image';
    const TYPE_VIDEO = 'video';
    const TYPE_TEXT  = 'text';

    /**
     * Initializes a Channel
     *
     * @param   string $applicationId     The Channel API's Application ID/Client ID/whatever name they use
     * @param   string $applicationSecret The Channel API's Application Secret, if needed
     * @param   string $applicationToken  Some API require the use of a client access_token
     * @param   array  $params            Additional API-specific parameters
     */
    public function __construct($applicationId, $applicationSecret = null, $applicationToken = null, $params = null) { }

    /**
     * Searches for content containing a specific hashtag
     *
     * @param   string $query       The keyword, or hashtag used in the search
     * @param   string $type        The type of content that will be kept : MEDIA_IMAGES_VIDEOS | MEDIA_IMAGES | MEDIA_VIDEOS | MEDIA_TEXT | MEDIA_ALL
     * @param   string $since       The limit from which contents will be returned (some APIs might not use this)
     * @param   bool   $pIncludeRaw Include Raw data in response
     *
     * @return  object The parsed data with the API
     */
    public function fetch($query, $type, $since = null, $pIncludeRaw = false) { }

    /**
     * Decode stringified JSON / string / DateTime object with since marker
     *
     * @param mixed $since          JSON / string / DateTime / null
     * @param bool $isDate          Whether possible DateTime object
     *
     * @return mixed                Null, marker string or ['date' => date iso, 'ignoreIds' => []]
    */
    protected function decodeSince($since, $query, $isDate = false)
    {
        if (isset($since)) {
            if (is_string($since)) {
                // Probably json
                $sinceJson = json_decode($since, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    if ($isDate) {
                        throw new Exception('Error parsing $since parameter as JSON.');
                    }
                } else if (isset($sinceJson[$query])) {
                    $since = $sinceJson[$query];
                    // Validate json data if date
                    if ($isDate) {
                        $since = new DateTime($since);
                    }
                } else {
                    // No since info for this query
                    $since = null;
                }
            } else {
                if ($isDate && !($since instanceof DateTime)) {
                    throw new Exception('Invalid $since parameter: must be either null or a DateTime object.');
                }
            }
        }

        return $since;
    }

    /**
     * Decode JSON body string to object.
     *
     * @param Guzzle\Http\Message\Response $pBody
     * @return stdClass
     */
    protected static function decodeBody(\Guzzle\Http\Message\Response $pResponse)
    {
        $data = json_decode($pResponse->getBody(true));
        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new Exception('Unable to parse response body into JSON');
        }

        if ($data === NULL) {
            $data = new stdClass;
        }

        return $data;
    }


    /**
     * Removes entries already fetched (for data-based APIs)
     *
     * @param object    $return Data fetched by fetch()
     * @param DateTime  $since  $since parameter (or null)
     * @return object           Data object without old entries
     */
    public static function removeOldEntries($return, $since)
    {
        $hasSince = isset($since) && ($since instanceof \DateTime);
        $results = $return->data;
        $return->original_data_count = count($results);

        $newSince = $since;
        if ($hasSince && ($return->original_data_count > 0)) {
            // Filter to leave only later than $since
            $results = array_filter($results, function($result) use ($since) {
                try {
                    $publishDate = new DateTime($result->created_at_orig);
                } catch (Exception $e) {
                    continue;
                }
                return $publishDate > $since;
            });
            $return->data = $results;
        }

        // Get latest result date
        foreach ($results as $result) {
            try {
                $publishDate = new DateTime($result->created_at_orig);
            } catch (Exception $e) {
                continue;
            }
            if (!$newSince || ($publishDate > $newSince)) {
                $newSince = $publishDate;
            }
        }

        $return->new_since = $newSince ? $newSince : null;

        return $return;
    }

    /**
     * Merges new page data, updates new_since
     *
     * @param object    $return     Data fetched by fetch()
     * @param DateTime  $newData    New data fetched by fetch()
     * @return object               Data object with items merged
     */
    public static function handleNewPage($return, $newData)
    {
        if (isset($newData->data)) {
            $dataSince = isset($return->new_since) ? $return->new_since : null;
            $newDataSince = isset($newData->new_since) ? $newData->new_since : null;
            if ($newDataSince) {
                if ($dataSince) {
                    if ($newDataSince > $dataSince) {
                        // Newer date, override
                        $dataSince = $newDataSince;
                    }
                } else {
                    $dataSince = $newDataSince;
                }
                $return->new_since = $dataSince;
            }
            $return->data = array_merge($return->data, $newData->data);
        }

        return $return;
    }
}
