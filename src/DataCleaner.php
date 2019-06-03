<?php

namespace Drupal\everylane;

use Drupal\Core\Database\Connection;

class DataCleaner {

  protected $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public function cleanStreetName($street) {
    // Philly special.
    if (strpos($street, 'CHRISTOPHER COLUMBUS') || strpos($street, 'DELAWARE')) {
      $street = 'DELAWARE AVE';
    }
    // Remove leading directionals.
    $street = preg_replace('/^[NESW]\ /', '', $street);
    $street = preg_replace('/\ PARKWAY$/', ' PKWY', $street);
    $street = preg_replace('/\ ROAD$/', ' RD', $street);
    $street = preg_replace('/\ AVENUE$/', ' AVE', $street);
    $street = preg_replace('/\ AV$/', ' AVE', $street);
    $street = preg_replace('/\ STREET$/', ' ST', $street);
    $street = preg_replace('/\ PLACE$/', ' PL', $street);
    $street = preg_replace('/\ DRIVE$/', ' DR', $street);
    $street = trim(preg_replace('/[^A-Za-z0-9\ ]/', ' ', $street));

    return str_replace(' ', '_', strtolower(trim($street)));
  }

  public function fixGroupNames() {
    $names = $this->database->query('SELECT streetname FROM bike_network')->fetchCol();
    foreach ($names as $name) {
      if (empty($name)) {
        continue;
      }
      $grouping = $this->cleanStreetName($name);
      $this->database->query("UPDATE bike_network SET grouping_streetname = :name WHERE streetname = :streetname", [':name' => $grouping, ':streetname' => $name]);
    }
  }

  public function assignBbox() {
    $query = $this->database->query("SELECT * FROM bike_network WHERE minx = 0");
    while ($row = $query->fetch()) {
      $segment = new Segment($row);
      $bbox = $segment->LineString->getBBox();
      $this->database->query("UPDATE bike_network set minx = :minx, maxx = :maxx, miny = :miny, maxy = :maxy WHERE seg_id = :seg_id", [':maxx' => $bbox['maxx'], ':minx' => $bbox['minx'], ':miny' => $bbox['miny'], ':maxy' => $bbox['maxy'], ':seg_id' => $segment->seg_id]);
    }
  }

  public function calculateComponents() {
    $query = $this->database->query('SELECT * FROM bike_network WHERE segment_count = 0');
    while ($row = $query->fetch()) {
      $segment = new Segment($row);
      if (!$segment->valid()) {
        $this->database->query("UPDATE bike_network SET segment_count = -1, note = 'Invalid geometry: not a MultiLineString.' WHERE seg_id = :seg_id", [':seg_id' => $row->seg_id]);
        \Drupal::logger('everylane')->info('Marked segment failed');
        continue;
      }
      $this->database->query("UPDATE bike_network SET segment_count = :n WHERE seg_id = :seg_id", [':seg_id' => $segment->seg_id, ':n' => $segment->LineString->numPoints()]);
    }
  }

}