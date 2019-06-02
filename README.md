Everylane
---------

#### Who
I am a Drupal developer, and I like to ride bikes.

#### What
This Drupal module is designed to orchestrate a twitter bot into posting pictures of bike lanes, with inspiration from [every lot bot](https://github.com/fitnr/everylotbot).

#### When
The bot, [https://twitter.com/everylanephilly](https://twitter.com/everylanephilly), should be tweeting about once an hour for a year, from the summer of 2019 through the summer of 2020.

#### How
First, you need a Drupal site. More instructions TK.

#### Where
This module is specific to Philadelphia. Support for additional cities TK.

#### Why
Inspired by the [everylot bots like everylotphilly](https://twitter.com/everylotphilly) I thought it would be interesting to build a similar bot to show what Philadelphia's bike network looks like. I hope the pictures will help shape your opinion about our infrastructure.


## Developer information
I'll be posting TODOs into the issues section. Level of interest from other parties will determine how much time I spend making this adaptable to other cities.

### Requirements
* A dataset with your bike lane network. See "data source details" below.
* Google maps API key, allowing Static Maps API and Street View Static API
* Twitter developer app: access key, access secret, consumer key, consumer secret

#### Set up Drupal
Thiese steps will install a minimal Drupal site on your local machine to run Everylane, but local installation is not a requirement. See [https://www.drupal.org/docs/official_docs/en/_evaluator_guide.html](https://www.drupal.org/docs/official_docs/en/_evaluator_guide.html) for more information.
```
# Install a standard Drupal site locally
mkdir drupal
cd drupal
curl -sSL https://www.drupal.org/download-latest/tar.gz | tar -xz --strip-components=1
php core/scripts/drupal quick-start standard

# Add and enable drush and everylane module
composer require drupal/everylane drush/drush
drush en everylane -y
```

### Configuration
```
# Set Google API key
drush cset everylane.settings google_maps_api_key 'REPLACE_WITH_YOUR_GOOGLE_API_KEY'

# Import your network. See "data source details" for requirements.
TK

# Clean up data - better process TK
## Assign streets to groupings, removing leading cardinal directions.
drush ev "\Drupal::service('everylane.data_cleaner')->fixGroupNames();"
## Assign bounding boxes to each segment definition, for ordering.
drush ev "\Drupal::service('everylane.data_cleaner')->assignBbox();"
## Assign segment count to each geometry, flagging any invalid geometries.
drush ev "\Drupal::service('everylane.data_cleaner')->calculateComponents();"

# Generate static maps - better process TK
while [ 1 ] ; do drush ev "\Drupal::service('everylane.static_mapper')->generateNextStaticMap();" done;

# Generate street view images - better process TK
while [ 1 ] ; do drush ev "\Drupal::service('everylane.street_viewer')->generateNextStreetViewSet();" done;

# Set Twitter API keys
drush cset everylane.settings twitter.oauth_access_token 'REPLACE_WITH_YOUR_TWITTER_OAUTH_ACCESS_TOKEN'
drush cset everylane.settings twitter.oauth_access_token_secret 'REPLACE_WITH_YOUR_TWITTER_OAUTH_SECRET'
drush cset everylane.settings twitter.consumer_key 'REPLACE_WITH_YOUR_TWITTER_CONSUMER_KEY'
drush cset everylane.settings twitter.consumer_secret 'REPLACE_WITH_YOUR_TWITTER_CONSUMER_SECRET'

# (optional) Change the minimum tweet interval offset. Defaults to "55 minutes".
# Each cron run will only attempt a tweet if the previous tweet is older than this interval.
# example: tweet as often as possible
drush cset everylane.settings minimum_time_between_tweets "now"

# Start tweeting!
drush cron
```

## Data source details
You'll need to get your dataset into Drupal's `bike_network` database table. As of now, the easiest way to do this is probably importing from CSV to mysql.
Minimum required fields are:

* `streetname` - human-readable name of bike lane streets
* `wkt` - WKT-formatted geometry definitions. May be either a single LineString or a MultiLineString. For MultiLineString, all geometries beyond the first are ignored.
* `type` - bike lane type, e.g. "Parking protected", "Off street trail", etc.
* `shape__length` - length, in ~feet.


## Sources
For Philadelphia, primary data source is [https://www.opendataphilly.org/dataset/bike-network](https://www.opendataphilly.org/dataset/bike-network)

The Map there is old, but I posted a new one here from most recent data (9 months old as of this writing): [https://aaron-bauman.carto.com/builder/50af099f-3e5a-47b3-8ab9-a604528a4f0d/embed](https://aaron-bauman.carto.com/builder/50af099f-3e5a-47b3-8ab9-a604528a4f0d/embed)
