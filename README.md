Everylane
---------

#### Who?
I am a Drupal developer, and I like to ride bikes. 

#### What?
This Drupal module is designed to orchestrate the twitter bot [Every Lane Philly](https://twitter.com/everylanephilly) into posting pictures of bike lanes, with inspiration from [every lot bot](https://github.com/fitnr/everylotbot).

#### When?
The bot, [https://twitter.com/everylanephilly](https://twitter.com/everylanephilly), should be tweeting about once an hour for a year, from the summer of 2019 through the summer of 2020.

#### Where?
This module was built specific for Philadelphia, but I would love to add wider support for other cities. Please DM me, post an issue, or create a pull request. 

#### Why?
Inspired by the [everylot bots like everylotphilly](https://twitter.com/everylotphilly) I thought it would be interesting to build a similar bot to show what Philadelphia's bike network looks like. I hope the pictures will help shape your opinion about our infrastructure.

#### How?
Great question! Keep reading...


## Install
I'll be posting TODOs into the issues section. Level of interest from other parties will determine how much time I spend making this adaptable to other cities.

### Requirements
* A dataset with your bike lane network. See [data source details](#data-source-details) below.
* Google maps API key, allowing Static Maps API and Street View Static API
* Twitter developer app: access key, access secret, consumer key, consumer secret

#### Set up Drupal
These steps will install a minimal Drupal site on your local machine to run Everylane, but local installation is not a requirement. See [https://www.drupal.org/docs/official_docs/en/_evaluator_guide.html](https://www.drupal.org/docs/official_docs/en/_evaluator_guide.html) for more information.
```
# Install a standard Drupal site locally
mkdir drupal
cd drupal
curl -sSL https://www.drupal.org/download-latest/tar.gz | tar -xz --strip-components=1
php core/scripts/drupal quick-start standard

# Add and enable drush and everylane module
# Note: everylane drush commands require drush/drush:^9
composer require drupal/everylane drush/drush
drush en everylane -y
```

### Configuration
```
# Set Google API key
drush cset everylane.settings google_maps_api_key 'REPLACE_WITH_YOUR_GOOGLE_API_KEY'

# Import your network into `bike_network` table. See "data source details" for requirements.
TK

# Clean up data, generate static maps, and generate street view images.
# Note: this command may take a long time, run out of resources, or throw other errors. Please report them to github!
drush everylane:init

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
# Use `drush everylane:generate:tweet` if you prefer.
drush cron
```

## Data source details
You'll need to get your dataset into Drupal's `bike_network` database table. As of now, the easiest way to do this is probably importing from CSV to mysql.
Minimum required fields are:

* `streetname` - human-readable name of bike lane streets
* `seg_id` - unique identifier for a segment. If undefined, one will be assigned.
* `wkt` - WKT-formatted geometry definitions. May be either a single LineString or a MultiLineString. For MultiLineString, all geometries beyond the first are ignored.
* `type` - bike lane type, e.g. "Parking protected", "Off street trail", etc. May not be necessary if you don't want this in your tweet text.
* `shape__length` - length, in ~feet. May not be necessary if you don't want this in your tweet text. TODO: calculate distance based on geometries.

NB: If you're using the quick install method above, consider a sqlite GUI tool like https://tableplus.io/ to help import your datasource.

## Sources
For Philadelphia, primary data source is [https://www.opendataphilly.org/dataset/bike-network](https://www.opendataphilly.org/dataset/bike-network)

The Map there is old, but I posted a new one here from most recent data (9 months old as of this writing): [https://aaron-bauman.carto.com/builder/50af099f-3e5a-47b3-8ab9-a604528a4f0d/embed](https://aaron-bauman.carto.com/builder/50af099f-3e5a-47b3-8ab9-a604528a4f0d/embed)

## Known limitations
* Very rudimentary duplicate filtering. Depending on the source data, you may end up generating some duplicates or near-duplicates.
* Overpass and underpass handling is limited. When two streets overlap vertically, google street view static API doesn't provide any way to specify which layer to capture. The result is some street view images are highways instead of underpasses.
* No handling of "no image found" response
* Limited off-street street view availability. Google's street view is mostly auto-routes. Some off-road paths are available.
* Bearings may be 180 degrees opposite of traffic / bike routes. This mostly depends on whether the geometry is defined in the same order as the flow of traffic.
