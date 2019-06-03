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
    $this->twitter = new \TwitterAPIExchange(\Drupal::config('everylane.settings')->get('twitter'));
  }

  /**
   * Return TRUE if last tweet was more than 1 hour ago.
   */
  public function checkTimestamp() {
    $timestamp = \Drupal::state()->get("everylane_last_timestamp", 0);
    $cutoff = \Drupal::state()->get('minimum_time_between_tweets',
      \Drupal::config('everylane.settings')->get('minimum_time_between_tweets'));
    if (time() > strtotime($cutoff, $timestamp)) {
      return TRUE;
    }
  }

  public function generateNextTweet($street = '') {
    if (!$this->checkTimestamp()) {
      \Drupal::logger('everylane')->info('Too early to tweet');
      return FALSE;
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
      return FALSE;
    }
    if ($parent_tweet = $this->generateTweetForStreet($street, $groups)) {
      if ($parent_tweet == FALSE) {
        \Drupal::logger('everylane')->info('Tweet sent. Timestamp updated.');
        \Drupal::state()->set("everylane_last_timestamp", time());
        return TRUE;
      }
      foreach ($groups as $segments) {
        foreach ($segments as $segment) {
          $parent_tweet = $this->generateTweetsForSegment($segment, $parent_tweet);
          if ($parent_tweet == FALSE) {
            \Drupal::logger('everylane')->info('Tweet sent. Timestamp updated.');
            \Drupal::state()->set("everylane_last_timestamp", time());
            return TRUE;
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
    if ($tweet_id = $this->database->query("SELECT tweet_id FROM bike_tweets WHERE grouping_streetname = :street AND seg_id IS NULL ORDER BY tweet_id DESC", [':street' => $street])->fetchField()) {
      return (object)['id' => $tweet_id];
    }
    $filename = $this->dataFetcher->getStaticMapImagePath($street);
    $media = file_get_contents($filename);
    $media_response = $this->twitter->buildOauth(self::UPLOAD_IMAGE_URL, 'POST')
      ->setPostfields([
        'media_data' => base64_encode($media)
      ])
      ->performRequest();
    $media_response = json_decode($media_response);
    $json_error = json_last_error();
    if ($json_error) {
      \Drupal::logger('everylane')->critical('JSON decoding exception: ' . print_r(json_last_error(), 1));
    }

    $tweet = [
      'media_ids' => $media_response->media_id,
      'status' => $this->getStreetTweetText($groups),
    ];

    // One final check to see if we've already tweeted this.
    if ($tweet_id = $this->database->query("SELECT tweet_id FROM bike_tweets WHERE text = :text ORDER BY tweet_id DESC", [':text' => $tweet['status']])->fetchField()) {
      return (object)['id' => $tweet_id];
    }

    $tweet_response = $this->twitter->buildOauth(self::POST_URL, 'POST')
      ->setPostfields($tweet)
      ->performRequest();
    $tweet_response = json_decode($tweet_response);
    $json_error = json_last_error();
    if ($json_error) {
      \Drupal::logger('everylane')->critical('JSON decoding exception: ' . print_r(json_last_error(), 1));
    }

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
    $name = trim(str_replace('  ', ' ', ucwords(strtolower($segment->streetname))));
    // Strip cardinals, since we're merging them anyway.
    $name = preg_replace('/^[NESW]\ /', '', $name);
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
    $type_word = count($types) === 1 ? 'type' : 'types';
    $types = implode(', ', $types);
    $text = "$name
length: $length miles
bike lane $type_word: $types";
    if (strlen($text) > 255) {
      $text = substr($text, 0, 255);
    }
    return $text;
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

  public function getSegmentTweetText(Segment $segment, $i) {
    $point = $segment->LineString->geometryN($i+1);
    $name = trim(str_replace('  ', ' ', ucwords(strtolower($segment->streetname))));

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

    $type = $segment->type;

    $text = "$name
bike lane type: $type
https://google.com/maps?q=" . $point->y() . ',' . $point->x();
    if (strlen($text) > 255) {
      $text = substr($text, 0, 255);
    }
    return $text;
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

    if ($tweet_id = $this->database->query("SELECT tweet_id FROM bike_tweets WHERE seg_id = :seg_id AND i = :i ORDER BY tweet_id DESC", [':seg_id' => $segment->seg_id, ':i' => $i])->fetchField()) {
      return (object)['id' => $tweet_id];
    }

    $media = file_get_contents($filename);
    $media_response = $this->twitter->buildOauth(self::UPLOAD_IMAGE_URL, 'POST')
      ->setPostfields([
        'media_data' => base64_encode($media)
      ])
      ->performRequest();
    \Drupal::logger('media response')->info(print_r($media_response, 1));
    $media_response = json_decode($media_response);
    $json_error = json_last_error();
    if ($json_error) {
      \Drupal::logger('everylane')->critical('JSON decoding exception: ' . print_r(json_last_error(), 1));
    }

    $point = $segment->LineString->geometryN($i+1);
    $tweet = [
      'media_ids' => $media_response->media_id,
      'status' => $this->getSegmentTweetText($segment, $i),
      'long' => $point->x(),
      'lat' => $point->y(),
      'display_coordinates' => 'true',
      'in_reply_to_status_id' => $parent_tweet->id,
    ];

    // One final check to see if we've already tweeted this.
    if ($tweet_id = $this->database->query("SELECT tweet_id FROM bike_tweets WHERE text = :text ORDER BY tweet_id DESC", [':text' => $tweet['status']])->fetchField()) {
      return (object)['id' => $tweet_id];
    }

    $tweet_response = $this->twitter->buildOauth(self::POST_URL, 'POST')
      ->setPostfields($tweet)
      ->performRequest();
    \Drupal::logger('tweet response')->info(print_r($tweet_response, 1));
    $tweet_response = json_decode($tweet_response);
    $json_error = json_last_error();
    if (!$json_error) {
      $parent_tweet = $tweet_response;
    }
    else {
      \Drupal::logger('everylane')->critical('JSON decoding exception: ' . print_r(json_last_error(), 1));
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