<?php

namespace Drupal\everylane\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\everylane\DataCleaner;
use Drupal\everylane\DataFetcher;
use Drupal\everylane\Segment;
use Drupal\everylane\StaticMapper;
use Drupal\everylane\StreetViewer;
use Drupal\everylane\Tweeter;
use Drush\Commands\DrushCommands;
use Symfony\Component\Finder\Finder;

/**
 * Everylane drush commands.
 */
class EverylaneCommands extends DrushCommands {

  protected $database;
  protected $dataCleaner;
  protected $dataFetcher;
  protected $fileSystem;
  protected $streetViewer;
  protected $staticMapper;
  protected $tweeter;

  /**
   * EverylaneTweeter constructor.
   */
  public function __construct(Connection $database, FileSystemInterface $file_system, DataFetcher $dataFetcher, DataCleaner $dataCleaner, StreetViewer $street_viewer, StaticMapper $static_mapper, Tweeter $tweeter) {
    $this->database = $database;
    $this->dataCleaner = $dataCleaner;
    $this->dataFetcher = $dataFetcher;
    $this->fileSystem = $file_system;
    $this->streetViewer = $street_viewer;
    $this->staticMapper = $static_mapper;
    $this->tweeter = $tweeter;
  }

  /**
   * Try to initialize the whole shebang.
   *
   * Clean data, generate static maps, and generate street view images.
   *
   * @command everylane:init
   */
  public function init() {
    $this->initData();
    $this->generateMaps();
    $this->generateStreetviews();
  }

  /**
   * Initialize bike network data.
   *
   * @command everylane:initData
   */
  public function initData() {
    $this->dataCleaner->fixGroupNames();
    $this->dataCleaner->assignBbox();
    $this->dataCleaner->calculateComponents();
  }

  /**
   * Generate static map images.
   *
   * This command may run out of resources. If this happens, just keep running
   * again and again until it finishes.
   *
   * @command everylane:generate:maps
   */
  public function generateMaps() {
    $count = 0;
    while ($street = $this->staticMapper->generateNextStaticMap()) {
      $this->logger->info('Static maps generated for {street}.', ['street' => $street]);
      $count++;
    }
    $this->logger->info('Static maps generation complete for {n} streets.', ['n' => $count]);
  }

  /**
   * Generate street view images.
   *
   * This command may run out of resources. If this happens, just keep running
   * again and again until it finishes.
   *
   * @command everylane:generate:streetviews
   */
  public function generateStreetviews() {
    $count = 0;
    while ($street = $this->streetViewer->generateNextStreetViewSet()) {
      $this->logger->info('Street view generated for {street}.', ['street' => $street]);
      $count++;
    }
    $this->logger->info('Street view generation complete for {n} streets.', ['n' => $count]);
  }

  /**
   * Send the next tweet.
   *
   * @command everylane:generate:tweet
   */
  public function sendNextTweet() {
    if ($this->tweeter->generateNextTweet()) {
      $this->logger->info('Tweet sent.');
    }
    else {
      $this->logger->error('Tweet not sent. Check logs for details.');
    }
  }

}
