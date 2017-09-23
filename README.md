# SocialCrawler

SocialCrawler is a PHP library for retrieving images and videos posts from the most popular social networks if they contains specific hashtags. It currently supports the following social networks:

- Facebook
- Instagram
- Twitter
- YouTube
- Google+

Additionally, includes [Juicer] aggregator support.


## Installation

The recommended way to install SocialCrawler is through [Composer](http://getcomposer.org).

```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php
```

Then add SocialCrawler as a dependency in your `composer.json` file.
```javascript
"require": {
    "heavenconseil/socialcrawler": "dev-master"
}
```

After installing, you need to require Composer's autoloader:

```php
require __DIR__ . '/vendor/autoload.php';
```

## Example usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use SocialCrawler\Crawler;
use SocialCrawler\Channel\Channel;

$crawler = new Crawler(array(
    'channels'  => array(
        'FacebookChannel'   => array(
            'id'        => 'YOUR_FACEBOOK_APP_ID',
            'secret'    => 'YOUR_FACEBOOK_APP_SECRET',
            'token'     => 'YOUR_FACEBOOK_ACCESS_TOKEN',
            'media'     => Channel::MEDIA_IMAGES_VIDEOS,
            'since'     => '1391425325'
        ),
        'InstagramChannel'  => array(
            'token'     => 'YOUR_INSTAGRAM_ACCESS_TOKEN',
            'media'     => Channel::MEDIA_VIDEOS
        ),
        'TwitterChannel'    => array(
            'id'        => 'YOUR_TWITTER_CONSUMER_KEY',
            'secret'    => 'YOUR_TWITTER_CONSUMER_SECRET',
            'media'     => Channel::MEDIA_IMAGES
        ),
        'YoutubeChannel'    => array(
            'media'     => Channel::MEDIA_IMAGES
        ),
        'GooglePlusChannel' => array(
            'id'        => 'YOUR_GOOGLEPLUS_APP_ID',
            'media'     => Channel::MEDIA_IMAGES
        ),
        'JuicerChannel' => array(
            'id'        => 'YOUR_JUICER_APP_ID',
            'media'     => Channel::MEDIA_ALL
        )
    ),
    'log'       => array(
        'path'  => __DIR__,
        'level' => Crawler::LOG_VERBOSE
    )
));
$data = $crawler->fetch('#hashtag'); // Fetch a specific hashtag
$data = $crawler->fetch(array('#hashtag1', '#hashtag2')); // Fetch multiple hashtags
$data = $crawler->fetch('from:{user}'); // Fetch content from a specific user (Username for Twitter, User ID for Instagram, Google+ and Facebook); unavailable for Juicer
$data = $crawler->fetch('user:{user}', true); // Fetch user informations with raw data
```

SocialCrawler can be initialized with an Array of `channels`, each item containing at least an `id` property and identified by the name of the class that will handle the operations.
The `log` options are optional. By default a *socialcrawler.log* file will be created in the SocialCrawler directory.

The output generated by SocialCrawler will look like this:

```javascript
{
    'FacebookChannel': {
        'new_since': '...',
        'data': [
            {
                'id': '...',
                'created_at': '...',
                'description': '...',
                'link': '...',
                'author': {
                    'id': '...',
                    'avatar': '...',
                    'fullname': '...',
                    'username': '...' // Will return fullname if username is not set
                },
                'thumb': '...',
                'source': '...',
                'type': 'image|video|text',
                'raw': { } // The raw data returned by the social network API
            }
        ]
    },
    'InstagramChannel': { },
    'TwitterChannel': { },
    'YoutubeChannel': { },
    'GooglePlusChannel': { }
}
```

## The `since` parameter

The value returned under `new_since` key should be passed to the subsequent call as `since`, to obtain newer results. This will allow e.g. cron-based jobs to incrementally add new entries to the database. The `new_since` string, which should be saved, usually won't exceed 50 characters, but may be longer, depending on actual queries.

For YouTube, Google+ and Juicer channels, you can also pass a `DateTime` object as `since`, to get the results created at or after the specified time.


## Social networks in details

### Facebook and Instagram

You have to use one of the partner's API, like [Juicer](#juicer) - your own application will not pass the review.

Additionally, [Some time ago](https://stackoverflow.com/questions/19034754/facebook-api-search-for-hashtag) Facebook has dropped the global post search API feature. Only fetching posts from a specified source (e.g. single user, fanpage) is allowed.

For Instagram, on the other hand, it is allowed to perform a global search.

<a name="facebook-note"></a>

Facebook important note: the URLs of the assets (images, videos) invalidate in approx. 10 days - this needs additional handling, like caching the assets on own server.


### Twitter, YouTube, Google+

All those channels work seamless.


### Juicer

[Juicer] is one of the most affordable solutions for aggregating posts from different sources. The API returns unified and consistent results from all social networks.

The tipical solution would be to use Juicer only for Instagram and Facebook networks.

See also the Facebook [important note](#facebook-note) on asset URLs.



## Support for more social networks

You can easily support more services (say, [Tumblr](http://www.tumblr.com/docs/en/api/v2) for example) by adding a new `TumblrChannel` that extends the abstract class `Channel`. It should have at least these public methods with the following signatures:

```php
 __construct($applicationId, $applicationSecret = null, $applicationToken = null, $params = null)
 fetch($query, $type, $since = null, $pIncludeRaw = false)
```

You can benefit from the [Guzzle](https://github.com/guzzle/guzzle) HTTP framework for your Channel as it's already used by SocialCrawler.


[Juicer]: https://www.juicer.io/
