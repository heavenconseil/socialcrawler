<?php
namespace SocialCrawler;

use Monolog\Logger,
    Monolog\Handler\StreamHandler,
    \stdClass,
    \DateTime;

class Crawler
{
    /**
     * Constants used by the logger
     */
    const LOG_DISABLED  = 999;
    const LOG_NORMAL    = Logger::INFO;
    const LOG_VERBOSE   = Logger::DEBUG;
    const LOG_ERROR     = Logger::ERROR;

    private static $logger;
    private static $logLevel;

    private $channels;
    private $options;

    /**
     * Initializes a Crawler instance
     *
     * The configuration:
     * - channels: An associative array in the following format
     *     - channel:   The classname of the Channel that will be used (FacebookChannel, InstagramChannel, ...)
     *         - id:        The Channel API App ID
     *         - secret:    The Channel API App secret
     *         - token:     The Channel API user Access Token
     *         - media:     The media type that will be fetched (default: Channel::MEDIA_ALL)
     *         - since:     If set, asks the API to find data from the specified timestamp
     * - log: An associative array in the following format
     *     - path:  The destination where the log file will be written (default: SocialCrawler base directory)
     *     - level: The minimum level required for a log to be recorded (default: Crawler::LOG_NORMAL)
     *
     * @param array $options The configuration options
     */
    public function __construct(array $options)
    {
        $logPath        = (isset($options['log']) and isset($options['log']['path'])) ? $options['log']['path'] : __DIR__;
        self::$logLevel = (isset($options['log']) and isset($options['log']['level'])) ? $options['log']['level'] : self::LOG_NORMAL;
        $this->options  = $options;

        // Initializing Monolog
        self::$logger = new Logger('SocialCrawler');
        self::$logger->pushHandler(new StreamHandler($logPath . '/socialcrawler.log', self::$logLevel));

        // Initializing Channels
        $this->channels = array();
        foreach ($options['channels'] as $channel => $config) {
            $className = 'SocialCrawler\\Channel\\' . $channel;
            if (class_exists($className)) {
                $this->channels[$channel] = new $className(
                    (isset($config['id']) and strlen($config['id']) > 0) ? $config['id'] : null,
                    (isset($config['secret']) and strlen($config['secret'])) > 0 ? $config['secret'] : null,
                    (isset($config['token']) and strlen($config['token'])) > 0 ? $config['token'] : null,
                    (isset($config['params']) ? $config['params'] : null)
                );
            }
        }
    }

    /**
     * Asks the registered Channels to find media entries containing the specified hashtag
     *
     * @param string $query       The keyword, or hashtag used in the search
     * @param bool   $pIncludeRaw Include Raw data in response
     *
     * @return object The global data retrieved by the registered Channels
     */
    public function fetch($query, $pIncludeRaw = false)
    {
        if (! is_array($query)) {
            $query = array($query);
        }

        self::log(
            $this,
            self::LOG_NORMAL,
            'Fetch started',
            array(
                'channels' => array_keys($this->channels),
                'query'    => implode(', ', $query)
            )
        );

        $output = array();
        foreach ($this->channels as $channelName => $channel) {
            $timer = microtime(true);

            $output[$channelName]            = new stdClass;
            $output[$channelName]->data      = array();
            $output[$channelName]->new_since = array();

            foreach ($query as $tag) {
                $result = $channel->fetch(
                    $tag,
                    (isset($this->options['channels'][$channelName]['media'])) ? $this->options['channels'][$channelName]['media'] : Channel\Channel::MEDIA_ALL,
                    (isset($this->options['channels'][$channelName]['since']) and strlen($this->options['channels'][$channelName]['since']) > 0) ? $this->options['channels'][$channelName]['since'] : null,
                    $pIncludeRaw
                );

                if ($result instanceof stdClass and isset($result->data) and is_array($result->data)) {
                    $output[$channelName]->data = array_merge($output[$channelName]->data, $result->data);
                } else if ($result instanceof stdClass and isset($result->data) and $result->data instanceof stdClass) {
                    $output[$channelName]->data = $result->data;
                }

                $output[$channelName]->new_since[$tag] = isset($result->new_since) ? $result->new_since : null;
            }

            if (false !== $output[$channelName]) {
                $output[$channelName]->data = $this->removeDuplicates($output[$channelName]->data);
                $count = count($output[$channelName]->data);
                $timer = round(microtime(true) - $timer, 3) * 1000;
                self::log(
                    $this,
                    self::LOG_NORMAL,
                    sprintf('- %s found %d results in %d ms', $channelName, $count, $timer)
                );
            } else {
                unset($output[$channelName]);
                self::log($this, self::LOG_NORMAL, '- ' . $channelName . ' failed');
            }
        }

        // Convert all new_since to json with channel name keys
        $output = array_map(function($channelOutput) {
            if (isset($channelOutput->new_since)) {
                $channelOutput->new_since = json_encode($channelOutput->new_since);
            }
            return $channelOutput;
        }, $output);

        self::log($this, self::LOG_NORMAL, 'Fetch finished');
        return $output;
    }


    /**
     * Removes entries with same id (for multiple hashtag fetch)
     *
     * @param array     $data Data property of the fetch result
     * @return array    Data without duplicates
     */
    public static function removeDuplicates($data)
    {
        return array_reduce($data, function($aggregateData, $currentDataItem) {
            $previousIds = array_reduce($aggregateData, function($a, $d) {
                $a[] = $d->id;
                return $a;
            }, []);
            if (!in_array($currentDataItem->id, $previousIds)) {
                $aggregateData[] = $currentDataItem;
            }
            return $aggregateData;
        }, []);
    }

    /**
     * Logs custom information throughout the Crawler activity
     *
     * @param class     $context    The caller instance (useful when using Crawler::LOG_VERBOSE)
     * @param int       $logLevel   The level of the message that should be logged
     * @param string    $message    The content of the message
     * @param array     $parameters Useful data that should be logged with the message
     */
    public static function log($context, $logLevel, $message, $parameters = array())
    {
        if (self::$logLevel == self::LOG_VERBOSE and false !== get_class($context)) {
            $parameters['class'] = get_class($context);
        }

        self::$logger->addRecord($logLevel, $message, $parameters);
    }
}
