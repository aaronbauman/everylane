<?php

namespace Drupal\everylane;

use LineString;
use MultiLineString;
use Point;

class Segment {
  const MAX_DISTANCE = 0.001;
  const MIN_DISTANCE = 0.00001;

  public $tweeted;
  public $position;
  public $seg_id;
  public $type;
  public $images_fetched;
  public $grouping_streetname;
  /**
   * @var LineString
   */
  public $LineString;
  public $streetname;
  public $valid;
  public $wkt;

  public function __construct(\stdClass $row) {
    foreach (get_object_vars($row) as $prop => $val) {
      $this->$prop = $val;
    }
    $this->valid = TRUE;
    $this->LineString = \Drupal::service('geophp.geophp')->load($row->wkt, 'wkt');
    if (!$this->LineString instanceof LineString) {
      if (!$this->LineString instanceof MultiLineString || !$this->LineString->geometryN(1) instanceof LineString) {
        $this->valid = FALSE;
      }
      $this->LineString = $this->LineString->geometryN(1);
    }
    $this->subsectPoints();
  }

  /**
   * Divide the LineString into points no more than ~360 feet apart.
   *
   * Adjust "MAX_DISTANCE" to tweak this parameter.
   */
  protected function subsectPoints() {
    $new_points = [];
    for ($i = 0; $i < $this->LineString->numPoints() - 1; $i++) {
      $linestring = $this->LineString;
      $point1 = $linestring->geometryN($i + 1);
      $point2 = $linestring->geometryN($i + 2);
      if (!$point1 instanceof Point || !$point2 instanceof Point) {
        continue;
      }
      $substring = new LineString([$point1, $point2]);
      // If the substring is greater than ~360 feet, break it up into roughly 360 foot chunks.
      if ($substring->length() > self::MAX_DISTANCE) {
        $new_points = array_merge($new_points, $this->bisect($point1, $point2));
      }
      else {
        $new_points[] = $point1;
        $new_points[] = $point2;
      }
    }
    $this->LineString = new LineString($new_points);
  }

  /**
   * Given 2 points, recursively divide them into points no more than MAX_DISTANCE apart.
   *
   * @return Point[]
   *   The subdivided point array, suitable for a new LineString.
   */
  protected function bisect(Point $point1, Point $point2) {
    $x = ($point1->x() + $point2->x()) / 2;
    $y = ($point1->y() + $point2->y()) / 2;
    $midpoint = new Point($x, $y);
    $subsegment = new LineString([$point1, $midpoint]);
    if ($subsegment->length() > self::MAX_DISTANCE) {
      $left_segment = $this->bisect($point1, $midpoint);
      $right_segment = $this->bisect($midpoint, $point2);
      return [$left_segment[0], $left_segment[1], $right_segment[0], $right_segment[1], $right_segment[2]];
    }
    else {
      return [$point1, $midpoint, $point2];
    }
  }

  public function valid() {
    return $this->valid;
  }
}