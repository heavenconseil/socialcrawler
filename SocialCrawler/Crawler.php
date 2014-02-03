<?php

namespace SocialCrawler;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Crawler
{
    const LOG_DISABLED  = 999;
    const LOG_NORMAL    = Logger::INFO;
    const LOG_VERBOSE   = Logger::DEBUG;
    const LOG_ERROR     = Logger::ERROR;

    private static $logger;
    private static $logLevel;

    private $channels;
    private $options;

    public function __construct($options) {
        // Setting options
        if (!is_array($options)) {
            throw new \Exception('SocialCrawler needs to be initialized with an $options Array.');
        }
        $logPath = isset($options['log']) && isset($options['log']['path']) ? $options['log']['path'] : __DIR__;
        self::$logLevel = isset($options['log']) && isset($options['log']['level']) ? $options['log']['level'] : self::LOG_NORMAL;
        $this->options = $options;

        // Initializing Monolog
        self::$logger = new Logger('SocialCrawler');
        self::$logger->pushHandler(new StreamHandler($logPath . '/socialcrawler.log', self::$logLevel));

        // Initializing Channels
        $this->channels = array();
        foreach ($options['channels'] as $channel => $config) {
            $className = 'SocialCrawler\\Channel\\' . $channel;
            if (class_exists($className) && isset($config['id'])) {
                $this->channels[$channel] = new $className($config['id'], isset($config['secret']) ? $config['secret'] : null, isset($config['token']) ? $config['token'] : null);
            }
        }
    }

    public function fetch($query) {
        self::log($this, self::LOG_NORMAL, 'Fetch started', array('channels' => array_keys($this->channels), 'query' => $query));

        $output = array();
        foreach ($this->channels as $channelName => $channel) {
            $timer = microtime(true);
            $output[$channelName] = $channel->fetch(
                $query,
                isset($this->options['channels'][$channelName]['media']) ? $this->options['channels'][$channelName]['media'] : Channel\Channel::MEDIA_IMAGES_VIDEOS,
                isset($this->options['channels'][$channelName]['since']) ? $this->options['channels'][$channelName]['since'] : null
            );

            if (false !== $output[$channelName]) {
                $count = count($output[$channelName]->data);
                $timer = round(microtime(true) - $timer, 3) * 1000;
                self::log($this, self::LOG_NORMAL, sprintf('- %s found %d results in %d ms', $channelName, $count, $timer));
            } else {
                unset($output[$channelName]);
                self::log($this, self::LOG_NORMAL, '- ' . $channelName . ' failed');
            }
        }

        self::log($this, self::LOG_NORMAL, 'Fetch finished');
        return $output;
    }

    public static function log($context, $logLevel, $message, $parameters = array()) {
        if (self::$logLevel == self::LOG_VERBOSE && false !== get_class($context)) {
            $parameters['class'] = get_class($context);
        }
        self::$logger->addRecord($logLevel, $message, $parameters);
    }
}
