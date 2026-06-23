<?php

namespace Drupal\farm_breeding\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves a canonical animal type key from free-text species/breed terms.
 *
 * farmOS stores animal species as free-text taxonomy terms in the
 * animal_type vocabulary (e.g. "Angus", "Jersey cattle", "Boer goat").
 * This service normalises those strings and maps them to the canonical
 * machine-name keys understood by GestationCalculator.
 *
 * Matching strategy (in order):
 *  1. Exact match after normalisation (lowercase, collapse spaces).
 *  2. Substring scan against species_aliases from farm_breeding.settings.
 *  3. Direct match against GestationCalculator known keys.
 *  4. Returns NULL — caller skips calculation rather than erroring.
 */
class SpeciesResolver {

  protected GestationCalculator $gestationCalculator;
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a SpeciesResolver.
   */
  public function __construct(GestationCalculator $gestation_calculator, ConfigFactoryInterface $config_factory) {
    $this->gestationCalculator = $gestation_calculator;
    $this->configFactory       = $config_factory;
  }

  /**
   * Attempts to resolve a canonical animal type key from a species term name.
   *
   * @param string $species_term_name
   *   Raw term name from the animal asset, e.g. "Angus", "Jersey cattle".
   *
   * @return string|null
   *   Canonical key (e.g. 'angus', 'dairy_cattle') or NULL if unrecognised.
   */
  public function resolve(string $species_term_name): ?string {
    if (empty($species_term_name)) {
      return NULL;
    }

    $normalised = $this->normalise($species_term_name);

    // 1. Direct match against known GestationCalculator keys.
    if ($this->gestationCalculator->isKnownType($normalised)) {
      return $normalised;
    }

    // 2. Substring scan against species_aliases config.
    //    Uses injected config factory — no static \Drupal::config() call.
    $aliases = $this->configFactory
      ->get('farm_breeding.settings')
      ->get('species_aliases') ?? [];

    foreach ($aliases as $substring => $canonical) {
      if (str_contains($normalised, $this->normalise((string) $substring))) {
        return $canonical;
      }
    }

    return NULL;
  }

  /**
   * Infers a species key from the dam asset(s) on a breeding log.
   *
   * Explicitly loads each referenced asset by ID to avoid relying on the
   * entity reference cache, which may not be populated during hook_entity_presave()
   * on new entity creation.
   *
   * @param object $breeding_log  A breeding log entity.
   * @return string|null          Canonical species key, or NULL if unresolvable.
   */
  public function resolveFromLogAssets($breeding_log): ?string {
    if ($breeding_log->get('asset')->isEmpty()) {
      return NULL;
    }

    // Collect target IDs explicitly — don't rely on ->entity magic property
    // which may be NULL during presave on new logs.
    $asset_ids = [];
    foreach ($breeding_log->get('asset') as $ref) {
      if (!empty($ref->target_id)) {
        $asset_ids[] = $ref->target_id;
      }
    }

    if (empty($asset_ids)) {
      return NULL;
    }

    // Explicitly load assets so we always have fully hydrated objects.
    $assets = \Drupal::entityTypeManager()
      ->getStorage('asset')
      ->loadMultiple($asset_ids);

    foreach ($assets as $asset) {
      if ($asset->bundle() !== 'animal') {
        continue;
      }

      if (!$asset->hasField('animal_type') || $asset->get('animal_type')->isEmpty()) {
        continue;
      }

      foreach ($asset->get('animal_type') as $term_ref) {
        // Load term explicitly for the same reason as assets above.
        $term = $term_ref->entity;
        if (!$term && !empty($term_ref->target_id)) {
          $term = \Drupal::entityTypeManager()
            ->getStorage('taxonomy_term')
            ->load($term_ref->target_id);
        }
        if (!$term) {
          continue;
        }

        $resolved = $this->resolve($term->label());
        if ($resolved !== NULL) {
          return $resolved;
        }
      }
    }

    return NULL;
  }

  /**
   * Normalises a string: lowercase, collapse whitespace, strip punctuation.
   */
  protected function normalise(string $input): string {
    $input = strtolower($input);
    $input = preg_replace('/[\-\(\)\/]/', ' ', $input);
    $input = preg_replace('/\s+/', ' ', $input);
    return trim($input);
  }

}
