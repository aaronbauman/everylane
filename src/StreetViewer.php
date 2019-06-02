<?php

namespace Drupal\everylane;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Point;
use LineString;
use SebastianBergmann\Diff\Line;

class StreetViewer {

  const SIZE = '1000x500';

  const FOV  = '140';

  const STREETVIEW_API_ENDPOINT = 'https://maps.googleapis.com/maps/api/streetview';

  protected $database;
  protected $dataCleaner;
  protected $dataFetcher;
  protected $fileSystem;
  public function __construct(Connection $database, FileSystemInterface $file_system, DataFetcher $dataFetcher, DataCleaner $dataCleaner) {
    $this->database = $database;
    $this->dataCleaner = $dataCleaner;
    $this->dataFetcher = $dataFetcher;
    $this->fileSystem = $file_system;
  }

  public function getBearing(Point $point1, Point $point2) {
    $lon1 = $point1->x();
    $lon2 = $point2->x();
    $lat1 = $point1->y();
    $lat2 = $point2->y();
    $bearing = (rad2deg(atan2(sin(deg2rad($lon2) - deg2rad($lon1)) * cos(deg2rad($lat2)), cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lon2) - deg2rad($lon1)))) + 360) % 360;
    \Drupal::logger('bearing')->info('bearing ' . $bearing . ' ' . print_r(func_get_args(), 1));
    return $bearing;
  }

  public function generateNextStreetViewSet($streetname = '') {
    $groups = $this->dataFetcher->getNextStreetviewSegmentSet($streetname);
    if (!$groups) {
      \Drupal::logger('everylane')->info('Failed to fetch next segment set ' . $streetname);
      return;
    }
    $this->createStreetViewImagesForSegmentSet($groups);
  }

  /**
   * @param Segment[][] $segment
   * @return int
   */
  public function createStreetViewImagesForSegmentSet(array $groups) {
    /** @var Segment[] $segments */
    foreach ($groups as $segments) {
      foreach ($segments as $segment) {
        $this->createStreetViewImagesForSegment($segment);
      }
    }
  }

  public function createStreetViewImagesForSegment(Segment $segment) {
    $success = 0;
    $point2 = NULL;
    $linestring = $segment->LineString;
    for ($i = 0; $i < $segment->LineString->numPoints() - 1; $i++) {
      $segment->position = $i;
      $point1 = $linestring->geometryN($i + 1);
      $point2 = $linestring->geometryN($i + 2);
      if (!$point1 instanceof Point || !$point2 instanceof Point) {
        \Drupal::logger('everylane')
          ->info('Failed to find points for segment ' . $segment->seg_id . ' section ' . $i);
        continue;
      }
      // If previous point is same as current point or next point, skip it.
      if ($point2->equals($point1)) {
        \Drupal::logger('everylane')->info('Skipping point equal to previous ' . $i . ' ' . print_r($point1, 1));
        continue;
      }
      $bearing = $this->getBearing($point1, $point2);
      $success += $this->fetchAndSaveStreetViewForPoint($point1, $bearing, $segment);
    }
    $segment->position++;
    $success += $this->fetchAndSaveStreetViewForPoint($point2, $bearing, $segment);
    if ($success > 0) {
      $this->database->query("UPDATE bike_network SET images_fetched = :num WHERE seg_id = :seg_id", [
        ':num' => $success,
        ':seg_id' => $segment->seg_id,
      ]);
    }
    else {
      \Drupal::logger('everylane')
        ->info('Failed to save street view for segment ' . $segment->seg_id . ' section ');
    }
  }

  public function createStreetViewImageForTerminal(Segment $segment) {
    $endpoint = $segment->LineString->endPoint();
    $next_to_last_point = $segment->LineString->geometryN($segment->LineString->numPoints() - 1);
    $bearing = $this->getBearing($next_to_last_point, $endpoint);
    $this->fetchAndSaveStreetViewForPoint($endpoint, $bearing, $segment);
  }

  public function fetchAndSaveStreetViewForPoint(Point $point, $bearing, Segment $segment) {
    static $seen = [];
    // Don't fetch the same point twice.
    if (!empty($seen[$point->y() . ',' . $point->x()])) {
      \Drupal::logger('everylane')->info('Skipping seen ' . print_r($point, 1));
      return 1;
    }
    $seen[$point->y() . ',' . $point->x()] = $point->y() . ',' . $point->x();

    $query = [
      'size' => self::SIZE,
      'key' => \Drupal::config('everylane.settings')->get('google_maps_api_key'),
      'heading' => $bearing,
      'location' => $point->y() . ',' . $point->x(),
      'fov' => self::FOV,
    ];
    \Drupal::logger('everylane')->info(print_r($query, 1));

    $url = self::STREETVIEW_API_ENDPOINT . '?' . http_build_query($query);
    if (!$url) {
      \Drupal::logger('everylane')
        ->info('Failed to generate street view url for point ' . print_r($point, 1));
      return 0;
    }
    \Drupal::logger('everylane')
      ->info('Fetching streetview url ' . $url);

    if ($path = $this->saveStreetViewForSegment($segment, $url)) {
      \Drupal::logger('everylane')
        ->info('Street view saved for point ' . print_r($point, 1) . ' path ' . $path);
      return 1;
    }
    return 0;
  }

  public function saveStreetViewForSegment(Segment $segment, $url) {
    // Create the street directory.
    $directory = $this->dataFetcher->getImageDirectory($segment->grouping_streetname);
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::MODIFY_PERMISSIONS | FileSystemInterface::CREATE_DIRECTORY)) {
      return FALSE;
    }
    // Fetch and save the static map image.
    // Return the path to the image, or FALSE on error.
    return system_retrieve_file($url, $this->dataFetcher->getStreetViewImagePath($segment, $segment->position), FALSE, FileSystemInterface::EXISTS_REPLACE);
  }


}