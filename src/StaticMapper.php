<?php

namespace Drupal\everylane;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Polyline;

class StaticMapper {

  const SIZE = '1000x500';

  const SCALE = 2;

  const API_KEY = GOOGLE_MAPS_API_KEY;

  const COLOR = '0xff0000ff';

  const WEIGHT = '4';

  const STATIC_MAPS_API_ENDPOINT = 'https://maps.googleapis.com/maps/api/staticmap';

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

  /**
   * @param Segment[] $segments
   *
   * @return string|void
   */
  public function fetchStaticMapUrlForSegments(array $segments) {
    $paths = '';
    foreach ($segments as $segment) {
      $points = [];
      // All the http query methods assume unique keys for query strings, but
      // google doesn't. So, we have to build the query manually.
      $paths .= '&path=color:' . self::COLOR . '|weight:' . self::WEIGHT . '|';

      /** @var \Point $point */
      foreach ($segment->LineString->getComponents() as $point) {
        $points[] = [$point->y(), $point->x()];
      }
      $paths = $paths . 'enc:' . Polyline::encode($points);
    }
    if (empty($paths)) {
      return;
    }
    $query = 'size=' . self::SIZE . '&key=' . self::API_KEY . '&scale=' . self::SCALE;
    $query .= $paths;
    $url = self::STATIC_MAPS_API_ENDPOINT . '?' . $query;
    return $url;
  }

  public function generateNextStaticMap() {
    $street = $this->dataFetcher->nextStreetnameForStaticMap();
    if (!$street) {
      \Drupal::logger('everylane')->info('Failed to fetch next street');
      return;
    }
    $segments = $this->dataFetcher->segmentSetByStreet($street);
    if (!$street) {
      \Drupal::logger('everylane')->info('Failed to fetch any segments for ' . $street);
      return;
    }
    $url = $this->fetchStaticMapUrlForSegments($segments);
    if (!$url) {
      \Drupal::logger('everylane')->info('Failed to fetch url for ' . $street);
      return;
    }
    if ($path = $this->saveStaticMapForStreet($street, $url)) {
      \Drupal::logger('everylane')->info('Static map generated for ' . $street . ' ' . $path);
      $this->database->query("UPDATE bike_network SET static_map_generated = 1 WHERE grouping_streetname = :name", [':name' => $street]);
    }
    else {
      \Drupal::logger('everylane')->info('Failed to generate static map generated for ' . $street);
    }
  }

  public function saveStaticMapForStreet($street, $url) {
    // Create the street directory.
    $dir = $this->dataFetcher->getImageDirectory($street);
    if (!$this->fileSystem->prepareDirectory($dir, FileSystemInterface::MODIFY_PERMISSIONS | FileSystemInterface::CREATE_DIRECTORY)) {
      return FALSE;
    }
    // Fetch and save the static map image.
    // Return the path to the image, or FALSE on error.
    return system_retrieve_file($url, $this->dataFetcher->getStaticMapImagePath($street), FALSE, FileSystemInterface::EXISTS_REPLACE);
  }

}