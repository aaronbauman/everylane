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
* A dataset with your bike lane network
* Google maps API key, allowing Static Maps API and Street View Static API
* Twitter developer app: access key, access secret, consumer key, consumer secret

### Installation
Thiese steps will install a minimal Drupal site on your local machine to run Everylane. See [https://www.drupal.org/docs/official_docs/en/_evaluator_guide.html](https://www.drupal.org/docs/official_docs/en/_evaluator_guide.html) for more information.
```
# Install a standard Drupal site locally
mkdir drupal
cd drupal
curl -sSL https://www.drupal.org/download-latest/tar.gz | tar -xz --strip-components=1
php core/scripts/drupal quick-start standard
# Add and enable drush and everylane module
composer require drupal/everylane drush/drush
drush en everylane -y
# Set Google API key
TK
# Import your network
TK
# Clean up data
TK
# Generate static maps - better process TK
while [ 1 ] ; do drush ev \Drupal::service('everylane.static_mapper')->generateNextStaticMap(); done;
# Generate street view images - better process TK
while [ 1 ] ; do drush ev \Drupal::service('everylane.street_viewer')->generateNextStaticMap(); done;
# Set Twitter API keys
TK
# Start tweeting!
drush cron
```

## Sources
For Philadelphia, primary data source is [https://www.opendataphilly.org/dataset/bike-network](https://www.opendataphilly.org/dataset/bike-network)

The Map there is old, but I posted a new one here from most recent data (9 months old as of this writing): [https://aaron-bauman.carto.com/builder/50af099f-3e5a-47b3-8ab9-a604528a4f0d/embed](https://aaron-bauman.carto.com/builder/50af099f-3e5a-47b3-8ab9-a604528a4f0d/embed)
