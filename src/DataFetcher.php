<?php

namespace Drupal\everylane;

use Drupal\Core\Database\Connection;

class DataFetcher {

  protected $database;
  protected $dataCleaner;

  public function __construct(Connection $database, DataCleaner $dataCleaner) {
    $this->database = $database;
    $this->dataCleaner = $dataCleaner;
  }

  public function getImageDirectory($street) {
    return 'public://everylane/' . $this->dataCleaner->cleanStreetName($street);
  }

  public function getStaticMapImagePath($street) {
    return $this->getImageDirectory($street) .  '/static-map-' . $this->dataCleaner->cleanStreetName($street) . '.jpg';
  }

  public function getStreetViewImagePath(Segment $segment, $i) {
    return $this->getImageDirectory($segment->grouping_streetname) .  '/street-view--' . $segment->grouping_streetname . '--' . $segment->seg_id . '--' . $i . '.jpg';
  }


  public function nextStreetnameForStaticMap() {
    $streetname = $this->database->query('SELECT grouping_streetname FROM bike_network WHERE static_map_generated = 0 AND segment_count > 0 ORDER BY seg_id LIMIT 1')->fetchField();
    if (!$streetname) {
      return FALSE;
    }
    return $streetname;
  }

  public function nextStreetnameForStreetView() {
    $streetname = $this->database->query('SELECT grouping_streetname FROM bike_network WHERE images_fetched = 0 AND segment_count > 0 ORDER BY seg_id LIMIT 1')->fetchField();
    if (!$streetname) {
      return FALSE;
    }
    return $streetname;
  }

  public function nextStreetnameForTweet() {
    $streetname = $this->database->query('SELECT grouping_streetname FROM bike_network WHERE tweeted = 0 ORDER BY seg_id LIMIT 1')->fetchField();
    if (!$streetname) {
      return FALSE;
    }
    return $streetname;
  }

  /**
   * @return Segment[]|void
   */
  public function getNextStreetviewSegmentSet($streetname = '') {
    $segments = $this->nextStreetviewSegmentSet($streetname);
    while (!is_array($segments)) {
      if ($segments === FALSE) {
        \Drupal::logger('everylane')->info('Segments exhausted');
        return;
      }
      $segments = $this->nextStreetviewSegmentSet($streetname);
    }
    return $segments;
  }

  /**
   * @param $streetname
   *
   * @return Segment[]
   */
  public function segmentSetByStreet($streetname) {
    $query = $this->database->query('SELECT * FROM bike_network WHERE grouping_streetname = :streetname', [':streetname' => $streetname]);
    $segments = [];
    while ($row = $query->fetch()) {
      $segment = new Segment($row);
      if (!$segment->valid()) {
        $this->database->query("UPDATE bike_network SET static_map_generated = -1, segment_count = -1, images_fetched = -1, note = 'Invalid geometry: not a MultiLineString.' WHERE seg_id = :seg_id", [':seg_id' => $row->seg_id]);
        continue;
      }
      $segments[] = $segment;
    }
    return $segments;
  }

  /**
   * @param Segment[] $segments
   * @throws \Exception
   */
  public function sortSegments(array $segments) {
    \Drupal::logger('everylane')->info('Sorting ' . count($segments) . ' segments');

    // Sort segments into groupings, matching head to toe.
    /** @var Segment[][] $groups */
    $groups = [];
    foreach ($segments as $i => $new_segment) {
      if (empty($groups)) {
        $groups[] = [$new_segment];
        continue;
      }
      $match = FALSE;
      foreach ($groups as &$group) {
        $group_start = reset($group);
        $group_end = end($group);
        if ($new_segment->LineString->startPoint()->x() == $group_end->LineString->endPoint()->x()) {
          \Drupal::logger('everylane')->info('Matched segments ' . $group_end->seg_id . ' and ' . $new_segment->seg_id);
          $group[] = $new_segment;
          $match = TRUE;
          break;
        }
        if ($new_segment->LineString->endPoint()->x() == $group_start->LineString->startPoint()->x()) {
          \Drupal::logger('everylane')->info('Matched segments ' . $group_start->seg_id . ' and ' . $new_segment->seg_id);
          array_unshift($group,  $new_segment);
          $match = TRUE;
          break;
        }
      }
      if (!$match) {
        \Drupal::logger('everylane')->info('Failed to match ' . $new_segment->seg_id);
        $groups[] = [$new_segment];
      }
    }
    return $groups;
  }

  /**
   * Return LineString, FALSE if segments are done, or NULL if next segment is invalid.
   *
   * @return bool|Segment[]|null
   */
  protected function nextStreetviewSegmentSet($street = '') {
    if (empty($street)) {
      $street = $this->nextStreetnameForStreetView();
    }
    if (!$street) {
      return FALSE;
    }
    \Drupal::logger('everylane')->info('Fetching segments for ' . $street);
    $segments = [];
    $query = $this->database->query('SELECT * FROM bike_network WHERE images_fetched >= 0 AND grouping_streetname = :name ORDER BY minx, miny', [':name' => $street]);
    while ($row = $query->fetch()) {
      $segment = new Segment($row);
      \Drupal::logger('everylane')->info('Fetched segment ' . $segment->seg_id);
      if (!$segment->valid()) {
        $this->database->query("UPDATE bike_network SET images_fetched = -1, note = 'Invalid geometry: not a MultiLineString.' WHERE seg_id = :seg_id", [':seg_id' => $row->seg_id]);
        \Drupal::logger('everylane')->info('Marked segment failed');
        continue;
      }
      $segments[] = $segment;
    }
    if (!$segments) {
      return FALSE;
    }
    return $this->sortSegments($segments);
  }

  public function getNextTwitterSegmentSet($streetname) {
    $segments = $this->nextTwitterSegmentSet($streetname);
    while (!is_array($segments)) {
      if ($segments === FALSE) {
        \Drupal::logger('everylane')->info('Segments exhausted');
        return;
      }
      $segments = $this->nextTwitterSegmentSet($streetname);
    }
    return $segments;
  }

  protected function nextTwitterSegmentSet($street = '') {
    if (empty($street)) {
      $street = $this->nextStreetnameForTweet();
    }
    if (empty($street)) {
      return FALSE;
    }
    \Drupal::logger('everylane')->info('Fetching segments for ' . $street);
    $segments = [];
    $query = $this->database->query('SELECT * FROM bike_network WHERE tweeted = 0 AND grouping_streetname = :name ORDER BY minx, miny', [':name' => $street]);
    while ($row = $query->fetch()) {
      $segment = new Segment($row);
      \Drupal::logger('everylane')->info('Fetched segment ' . $segment->seg_id);
      if (!$segment->valid()) {
        $this->database->query("UPDATE bike_network SET tweeted = -1, note = 'Invalid geometry: not a MultiLineString.' WHERE seg_id = :seg_id", [':seg_id' => $row->seg_id]);
        \Drupal::logger('everylane')->info('Marked segment failed');
        continue;
      }
      $segments[] = $segment;
    }
    if (!$segments) {
      return FALSE;
    }
    return $this->sortSegments($segments);
  }


}