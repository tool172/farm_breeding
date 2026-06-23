<?php

namespace Drupal\farm_breeding\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Calculates gestation periods and due dates from config.
 *
 * All species data lives in farm_breeding.settings so site admins can
 * override any value without touching code.
 */
class GestationCalculator {

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * Human-readable labels. These are UI strings — not operational data —
   * so they stay in code and go through t() normally.
   */
  protected static array $labels = [
    'cattle'       => 'Cattle (generic)',
    'beef_cattle'  => 'Beef cattle',
    'dairy_cattle' => 'Dairy cattle',
    'angus'        => 'Angus',
    'hereford'     => 'Hereford',
    'simmental'    => 'Simmental',
    'charolais'    => 'Charolais',
    'holstein'     => 'Holstein',
    'jersey'       => 'Jersey',
    'sheep'        => 'Sheep',
    'goat'         => 'Goat',
    'pig'          => 'Pig / Swine',
    'horse'        => 'Horse',
    'pony'         => 'Pony',
    'donkey'       => 'Donkey',
    'alpaca'       => 'Alpaca',
    'llama'        => 'Llama',
    'bison'        => 'Bison',
    'deer'         => 'Deer',
    'elk'          => 'Elk',
    'rabbit'       => 'Rabbit',
  ];

  /**
   * Constructs a GestationCalculator.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->settings = $config_factory->get('farm_breeding.settings');
  }

  /**
   * Returns options array for select lists — machine_name => human label.
   */
  public function getAnimalTypeOptions(): array {
    return static::$labels;
  }

  /**
   * Returns TRUE if a machine name is a recognised animal type.
   */
  public function isKnownType(string $animal_type): bool {
    return $this->getTypicalDays($animal_type) !== NULL;
  }

  /**
   * Returns the typical gestation length in days, or NULL if unknown.
   */
  public function getTypicalDays(string $animal_type): ?int {
    $val = $this->settings->get('gestation_days.' . strtolower(trim($animal_type)));
    return $val !== NULL ? (int) $val : NULL;
  }

  /**
   * Returns the minimum (earliest) gestation length in days.
   */
  public function getMinDays(string $animal_type): ?int {
    $val = $this->settings->get('gestation_days_min.' . strtolower(trim($animal_type)));
    return $val !== NULL ? (int) $val : NULL;
  }

  /**
   * Returns the maximum (latest) gestation length in days.
   */
  public function getMaxDays(string $animal_type): ?int {
    $val = $this->settings->get('gestation_days_max.' . strtolower(trim($animal_type)));
    return $val !== NULL ? (int) $val : NULL;
  }

  /**
   * Calculates the expected (typical) due date as a Unix timestamp.
   *
   * @param int $breeding_timestamp  Unix timestamp of breeding/conception.
   * @param string $animal_type      Canonical machine name.
   * @return int|null
   */
  public function calculateDueDate(int $breeding_timestamp, string $animal_type): ?int {
    $days = $this->getTypicalDays($animal_type);
    return $days !== NULL ? $breeding_timestamp + ($days * 86400) : NULL;
  }

  /**
   * Calculates the earliest possible due date (minimum gestation).
   */
  public function calculateEarliestDueDate(int $breeding_timestamp, string $animal_type): ?int {
    $days = $this->getMinDays($animal_type);
    return $days !== NULL ? $breeding_timestamp + ($days * 86400) : NULL;
  }

  /**
   * Calculates the latest possible due date (maximum gestation).
   */
  public function calculateLatestDueDate(int $breeding_timestamp, string $animal_type): ?int {
    $days = $this->getMaxDays($animal_type);
    return $days !== NULL ? $breeding_timestamp + ($days * 86400) : NULL;
  }

  /**
   * Calculates the suggested pregnancy check date.
   *
   * Returns NULL if the check interval for this species is 0 or unset.
   */
  public function calculateCheckDueDate(int $breeding_timestamp, string $animal_type): ?int {
    $days = $this->settings->get('pregnancy_check_days.' . strtolower(trim($animal_type)));
    if (empty($days)) {
      return NULL;
    }
    return $breeding_timestamp + ((int) $days * 86400);
  }

  /**
   * Returns a human-readable gestation summary, e.g. "283 days (279–287)".
   */
  public function getSummary(string $animal_type): string {
    $typical = $this->getTypicalDays($animal_type);
    if ($typical === NULL) {
      return 'Unknown species';
    }
    $min = $this->getMinDays($animal_type);
    $max = $this->getMaxDays($animal_type);
    if ($min !== NULL && $max !== NULL) {
      return "{$typical} days (range: {$min}–{$max})";
    }
    return "{$typical} days";
  }

}
