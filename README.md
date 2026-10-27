# Laravel Keywords Ahrefs

Ahrefs Keywords Explorer driver for the
[Laravel Keywords Bridge](https://github.com/aimeos/laravel-keywords-bridge)
using the Ahrefs API v3.

## Installation

```bash
composer require aimeos/laravel-keywords-ahrefs
```

## Configuration

Add these settings to your `.env` file and set `KEYWORDS_DRIVER=ahrefs`:

```
AHREFS_API_KEY=...
AHREFS_COUNTRY=us
```

* `AHREFS_API_KEY`: API key from your Ahrefs account settings (requires a plan with API access)
* `AHREFS_COUNTRY`: Two letter ISO country code sent if no country is passed (the API requires one)

Optional settings in the `ahrefs` section of `./config/keywords-bridge.php`:

* `timeout`: Request timeout in seconds (default: `30`)

## Usage

```php
use Aimeos\KeywordsBridge\Facades\Keywords;

// worldwide search volume
$result = Keywords::volume(['laravel cms', 'headless cms']);

// related keywords in Germany
$result = Keywords::related('laravel cms', 'de', 100);
```

* Without a country, the worldwide volume (`global_volume`) is returned.
* Volume uses the `keywords-explorer/overview` endpoint (100 keywords per request).
* Related keywords use the `keywords-explorer/related-terms` endpoint, ordered by volume (at most 1000).
* Keywords containing commas can't be passed to the API and are returned with a `null` volume.
* Each request consumes API units of your Ahrefs plan.

## License

This package is released under the LGPL-2.1+ License.
