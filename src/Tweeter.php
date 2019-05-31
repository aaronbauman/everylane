<?php

namespace Drupal\everylane;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;

class Tweeter {

  // e.g. https://2krkh83j5a.execute-api.us-east-1.amazonaws.com/default/everylaneredirect?heading=-45&viewpoint=39.896637200856,-75.237934310726
  const LINK_TEMPLATE = 'https://2krkh83j5a.execute-api.us-east-1.amazonaws.com/default/everylaneredirect';

  const POST_URL = 'https://api.twitter.com/1.1/statuses/update.json';

  const UPLOAD_IMAGE_URL = 'https://upload.twitter.com/1.1/media/upload.json';

  protected $database;
  protected $dataCleaner;
  protected $dataFetcher;
  protected $fileSystem;
  protected $streetViewer;

  /**
   * EverylaneTweeter constructor.
   */
  public function __construct(Connection $database, FileSystemInterface $file_system, DataFetcher $dataFetcher, DataCleaner $dataCleaner, StreetViewer $street_viewer) {
    $this->database = $database;
    $this->dataCleaner = $dataCleaner;
    $this->dataFetcher = $dataFetcher;
    $this->fileSystem = $file_system;
    $this->streetViewer = $street_viewer;
    $this->twitter = new \TwitterAPIExchange(\Drupal::config('everylane')->get('twitter'));
  }

  /**
   * Return TRUE if last tweet was more than 1 hour ago.
   */
  public function checkTimestamp() {
    $timestamp = \Drupal::state()->get("everylane_last_timestamp", 0);
    if ($timestamp < strtotime(\Drupal::config('everylane')->get('minimum_time_between_tweets'))) {
      return TRUE;
    }
  }

  public function generateNextTweet($street = '') {
    if (!$this->checkTimestamp()) {
      \Drupal::logger('everylane')->info('Too early to tweet');
      return;
    }
    if (empty($street)) {
      $street = $this->dataFetcher->nextStreetnameForTweet();
    }
    if (empty($street)) {
      \Drupal::logger('everylane')->critical('No street found');
      return FALSE;
    }
    $groups = $this->dataFetcher->getNextTwitterSegmentSet($street);
    if (empty($groups)) {
      \Drupal::logger('everylane')->critical('No groups fetched');
      return;
    }
    if ($parent_tweet = $this->generateTweetForStreet($street, $groups)) {
      if ($parent_tweet == FALSE) {
        \Drupal::logger('everylane')->info('Tweet sent. Timestamp updated.');
        \Drupal::state()->set("everylane_last_timestamp", time());
        return;
      }
      foreach ($groups as $segments) {
        foreach ($segments as $segment) {
          $parent_tweet = $this->generateTweetsForSegment($segment, $parent_tweet);
          if ($parent_tweet == FALSE) {
            \Drupal::logger('everylane')->info('Tweet sent. Timestamp updated.');
            \Drupal::state()->set("everylane_last_timestamp", time());
            return;
          }
        }
      }
      \Drupal::logger('everylane')->info('Completed street ' . $street);
      $this->database->query("UPDATE bike_network SET tweeted = 1 WHERE grouping_streetname = :street", [':street' => $street]);
    }
  }

  /**
   * Returns FALSE if a tweet gets sent. Otherwise, returns a tweet_id from database.
   */
  public function generateTweetForStreet($street, $groups) {
    if ($tweet_id = $this->database->query("SELECT tweet_id FROM bike_tweets WHERE grouping_streetname = :street AND seg_id IS NULL", [':street' => $street])->fetchField()) {
      return (object)['id' => $tweet_id];
    }
    $filename = $this->dataFetcher->getStaticMapImagePath($street);
    $media = file_get_contents($filename);
    $media_response = $this->twitter->buildOauth(self::UPLOAD_IMAGE_URL, 'POST')
      ->setPostfields([
        'media_data' => base64_encode($media)
      ])
      ->performRequest();
    \Drupal::logger('media response')->info(print_r($media_response));
    $media_response = json_decode($media_response);

    $tweet = [
      'media_ids' => $media_response->media_id,
      'status' => $this->getStreetTweetText($groups),
    ];
    $tweet_response = $this->twitter->buildOauth(self::POST_URL, 'POST')
      ->setPostfields($tweet)
      ->performRequest();
    \Drupal::logger('tweet response')->info(print_r($tweet_response));
    $tweet_response = json_decode($tweet_response);

    try {
      $query = $this->database->insert('bike_tweets');
      $query->fields([
        'media_id' => $media_response->media_id,
        'tweet_id' => $tweet_response->id,
        'grouping_streetname' => $street,
        'filename' => $filename,
        'text' => $tweet['status'],
        'sent' => 1,
      ])->execute();
    }
    catch (\Exception $e) {
      watchdog_exception('everylane', $e);
      // already there?
    }
    return FALSE;
  }

  public function getStreetTweetText($groups) {
    $segment = current(current($groups));
    $name = str_replace('  ', ' ', ucwords(strtolower($segment->streetname)));
    $count = count($groups);
    $length = 0;
    $types = [];
    foreach ($groups as $group) {
      foreach ($group as $segment) {
        $length += $segment->shape__length;
        $types[$segment->type] = $segment->type;
      }
    }
    $length = number_format($length / 5280, 2);
    $types = implode(', ', $types);
    $count_word = $count == 1 ? 'segment' : 'segments';
    return "$name
$count $count_word
$length miles

$types";
  }

  /**
   * Returns FALSE if a tweet gets sent. Otherwise, returns a tweet_id from database.
   */
  public function generateTweetsForSegment(Segment $segment, $parent_tweet) {
    for ($i = 0; $i < $segment->LineString->numPoints() - 1; $i++) {
      $parent_tweet = $this->tweetPoint($segment, $parent_tweet, $i);
      if ($parent_tweet == FALSE) {
        return FALSE;
      }
    }
    $parent_tweet = $this->tweetPoint($segment, $parent_tweet, $segment->LineString->numPoints());
    if ($parent_tweet == FALSE) {
      return FALSE;
    }
    return $parent_tweet;
  }

  /**
   * Return a twitter response (with id) if a tweet was already sent (from the database).
   * Return FALSE if a tweet was just now sent, and processing should stop.
   */
  public function tweetPoint(Segment $segment, $parent_tweet, $i) {
    $filename = $this->dataFetcher->getStreetViewImagePath($segment, $i);
    if (!file_exists($filename)) {
      return $parent_tweet;
    }

    if ($tweet_id = $this->database->query("SELECT tweet_id FROM bike_tweets WHERE seg_id = :seg_id AND i = :i", [':seg_id' => $segment->seg_id, ':i' => $i])->fetchField()) {
      return (object)['id' => $tweet_id];
    }

    $media = file_get_contents($filename);
    $media_response = $this->twitter->buildOauth(self::UPLOAD_IMAGE_URL, 'POST')
      ->setPostfields([
        'media_data' => base64_encode($media)
      ])
      ->performRequest();
    \Drupal::logger('media response')->info(print_r($media_response));
    $media_response = json_decode($media_response);
    $point = $segment->LineString->geometryN($i+1);

    // If we're not at the endpoint, get bearing from next point.
    if ($i + 2 <= $segment->LineString->numPoints()) {
      $nextPoint = $segment->LineString->geometryN($i + 2);
      $bearing = $this->streetViewer->getBearing($point, $nextPoint);
    }
    else {
      // If we are at the endpoint, get bearing from previous.
      $prevPoint = $segment->LineString->geometryN($i - 1);
      $bearing = $this->streetViewer->getBearing($prevPoint, $point);
    }

    $tweet = [
      'media_ids' => $media_response->media_id,
      'status' => self::LINK_TEMPLATE . '?viewpoint=' . $point->y() . ',' . $point->x() . '&heading=' . $bearing,
      'long' => $point->x(),
      'lat' => $point->y(),
      'display_coordinates' => 'true',
      'in_reply_to_status_id' => $parent_tweet->id,
    ];
    $tweet_response = $this->twitter->buildOauth(self::POST_URL, 'POST')
      ->setPostfields($tweet)
      ->performRequest();
    \Drupal::logger('tweet response')->info(print_r($tweet_response));
    $tweet_response = json_decode($tweet_response);
    if (!json_last_error()) {
      $parent_tweet = $tweet_response;
    }

    try {
      $query = $this->database->insert('bike_tweets');
      $query->fields([
        'media_id' => $media_response->media_id,
        'tweet_id' => $tweet_response->id,
        'in_reply_to_id' => $parent_tweet->id,
        'grouping_streetname' => $segment->grouping_streetname,
        'seg_id' => $segment->seg_id,
        'i' => $i,
        'filename' => $filename,
        'text' => $tweet['status'],
        'sent' => 1,
      ])->execute();
    }
    catch (\Exception $e) {
      watchdog_exception('everylane', $e);
      // already there?
    }
    return FALSE;
  }

}