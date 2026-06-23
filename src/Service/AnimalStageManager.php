<?php

namespace Drupal\farm_breeding\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages the animal_stage field on Animal assets.
 *
 * DESIGN RATIONALE
 * ================
 * farmOS natively provides sex (male/female) and is_sterile (boolean) on
 * every Animal asset.  Adding species-specific flags like 'heifer', 'doe',
 * 'gilt' creates two sources of truth and breaks for multi-species operations.
 *
 * Instead, we maintain a single animal_stage bundle field with species-neutral
 * vocabulary:
 *
 *   juvenile        — newborn / young, not reproductively mature
 *   immature_female — female, never produced offspring  (heifer / doeling / gilt)
 *   mature_female   — female, has produced offspring    (cow / doe / sow)
 *   intact_male     — intact male, any age              (bull / buck / boar)
 *   castrated_male  — castrated/sterilised male         (steer / wether / barrow)
 *
 * Species-specific display labels (heifer, doe, etc.) are derived at render
 * time from animal_stage + the animal's species term.  The stored value is
 * always the neutral key.
 *
 * TRANSITION RULES (driven by events, not manual entry)
 * =====================================================
 *  Birth of new animal:
 *    sex=female → juvenile
 *    sex=male   → juvenile
 *
 *  Animal matures (manual, or future cron):
 *    juvenile + sex=female → immature_female
 *    juvenile + sex=male   → intact_male
 *    juvenile + is_sterile → castrated_male
 *
 *  Dam produces first offspring (Birth log):
 *    immature_female → mature_female
 *
 *  Castration event (future: dedicated log type):
 *    intact_male → castrated_male
 *    juvenile (male) → castrated_male
 */
class AnimalStageManager {

  /**
   * The neutral stage vocabulary.
   * These are the stored values — not display labels.
   */
  const STAGES = [
    'juvenile'        => 'Juvenile (calf / kid / lamb / piglet)',
    'immature_female' => 'Immature female (heifer / doeling / gilt / ewe lamb)',
    'mature_female'   => 'Mature female (cow / doe / sow / ewe)',
    'intact_male'     => 'Intact male (bull / buck / boar / ram)',
    'castrated_male'  => 'Castrated male (steer / wether / barrow)',
  ];

  /**
   * Species-specific display labels for each stage.
   * Key = canonical species key from GestationCalculator.
   * Value = [stage => species_specific_label].
   */
  const SPECIES_LABELS = [
    'cattle' => [
      'juvenile'        => 'Calf',
      'immature_female' => 'Heifer',
      'mature_female'   => 'Cow',
      'intact_male'     => 'Bull',
      'castrated_male'  => 'Steer',
    ],
    'beef_cattle' => [
      'juvenile'        => 'Calf',
      'immature_female' => 'Heifer',
      'mature_female'   => 'Cow',
      'intact_male'     => 'Bull',
      'castrated_male'  => 'Steer',
    ],
    'dairy_cattle' => [
      'juvenile'        => 'Calf',
      'immature_female' => 'Heifer',
      'mature_female'   => 'Cow',
      'intact_male'     => 'Bull',
      'castrated_male'  => 'Steer',
    ],
    'sheep' => [
      'juvenile'        => 'Lamb',
      'immature_female' => 'Ewe lamb',
      'mature_female'   => 'Ewe',
      'intact_male'     => 'Ram',
      'castrated_male'  => 'Wether',
    ],
    'goat' => [
      'juvenile'        => 'Kid',
      'immature_female' => 'Doeling',
      'mature_female'   => 'Doe',
      'intact_male'     => 'Buck',
      'castrated_male'  => 'Wether',
    ],
    'pig' => [
      'juvenile'        => 'Piglet',
      'immature_female' => 'Gilt',
      'mature_female'   => 'Sow',
      'intact_male'     => 'Boar',
      'castrated_male'  => 'Barrow',
    ],
    'horse' => [
      'juvenile'        => 'Foal',
      'immature_female' => 'Filly',
      'mature_female'   => 'Mare',
      'intact_male'     => 'Stallion',
      'castrated_male'  => 'Gelding',
    ],
    'alpaca' => [
      'juvenile'        => 'Cria',
      'immature_female' => 'Immature female',
      'mature_female'   => 'Female (hembra)',
      'intact_male'     => 'Male (macho)',
      'castrated_male'  => 'Castrated male',
    ],
    'llama' => [
      'juvenile'        => 'Cria',
      'immature_female' => 'Immature female',
      'mature_female'   => 'Female',
      'intact_male'     => 'Male',
      'castrated_male'  => 'Castrated male',
    ],
  ];

  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface $logger;

  public function __construct(ConfigFactoryInterface $config_factory, LoggerInterface $logger) {
    $this->configFactory = $config_factory;
    $this->logger        = $logger;
  }

  // ------------------------------------------------------------------ //
  // LABEL HELPERS                                                       //
  // ------------------------------------------------------------------ //

  /**
   * Returns the species-specific display label for a given stage + species.
   *
   * Falls back to the generic neutral label if no species mapping exists.
   *
   * @param string $stage    Neutral stage key (e.g. 'immature_female').
   * @param string $species  Canonical species key (e.g. 'cattle').  Optional.
   * @return string
   */
  public function getLabel(string $stage, string $species = ''): string {
    if (!empty($species) && isset(self::SPECIES_LABELS[$species][$stage])) {
      return self::SPECIES_LABELS[$species][$stage];
    }
    return self::STAGES[$stage] ?? $stage;
  }

  /**
   * Returns the full label map for a given species (for select lists).
   *
   * @param string $species  Canonical species key.  Optional.
   * @return array           stage_key => display_label
   */
  public function getStageOptions(string $species = ''): array {
    if (!empty($species) && isset(self::SPECIES_LABELS[$species])) {
      return self::SPECIES_LABELS[$species];
    }
    return self::STAGES;
  }

  // ------------------------------------------------------------------ //
  // DERIVATION FROM NATIVE FIELDS                                       //
  // ------------------------------------------------------------------ //

  /**
   * Derives the correct stage from an animal asset's native fields.
   *
   * Uses sex + is_sterile (or is_castrated on older farmOS) + current stage
   * to compute the appropriate starting stage for new or updated animals.
   *
   * This is the initial assignment logic — it does NOT demote a mature_female
   * back to immature_female, etc.
   *
   * @param object $asset  An animal asset entity.
   * @return string|null   Stage key, or NULL if it cannot be determined.
   */
  public function deriveStageFromNativeFields($asset): ?string {
    if ($asset->bundle() !== 'animal') {
      return NULL;
    }

    $sex = $this->getSex($asset);
    $is_sterile = $this->isSterile($asset);

    if ($is_sterile) {
      return 'castrated_male';  // Sterile/castrated, regardless of sex.
    }

    if ($sex === 'female') {
      // New animal with birthdate = juvenile; existing animal without stage = immature_female.
      $has_birthdate = $asset->hasField('birthdate') && !$asset->get('birthdate')->isEmpty();
      return $has_birthdate ? 'juvenile' : 'immature_female';
    }

    if ($sex === 'male') {
      $has_birthdate = $asset->hasField('birthdate') && !$asset->get('birthdate')->isEmpty();
      return $has_birthdate ? 'juvenile' : 'intact_male';
    }

    return NULL;  // Sex unknown — don't assign a stage.
  }

  // ------------------------------------------------------------------ //
  // STAGE TRANSITIONS                                                   //
  // ------------------------------------------------------------------ //

  /**
   * Advances an immature female to mature female (first calving).
   *
   * Called by BreedingLifecycleManager when a Birth log is saved.
   * Safe to call on animals already at mature_female (no change, no error).
   *
   * @param object $asset  An animal asset entity.
   * @param bool $save     Whether to save the asset after updating.
   * @return bool          TRUE if the stage was updated, FALSE if no change needed.
   */
  public function advanceToMatureFemale($asset, bool $save = TRUE): bool {
    if ($asset->bundle() !== 'animal') {
      return FALSE;
    }

    if (!$asset->hasField('animal_stage')) {
      return FALSE;
    }

    $current = $asset->get('animal_stage')->value;

    // Only advance from juvenile or immature_female.
    if (!in_array($current, ['juvenile', 'immature_female'], TRUE)) {
      return FALSE;  // Already mature_female, intact_male, castrated_male — don't touch.
    }

    // Confirm the animal is female via native sex field.
    if ($this->getSex($asset) !== 'female') {
      return FALSE;
    }

    $asset->set('animal_stage', 'mature_female');

    if ($save) {
      $asset->save();
    }

    $this->logger->info(
      'Animal @id (@name): @from → mature_female (first offspring recorded).',
      ['@id' => $asset->id(), '@name' => $asset->label(), '@from' => $current]
    );

    return TRUE;
  }

  /**
   * Transitions a juvenile to the appropriate mature stage when old enough.
   *
   * This is provided for manual calls or future cron-based promotion.
   * It does NOT run automatically — age-based transitions require reliable
   * birthdate data across the herd.
   *
   * @param object $asset
   * @param bool $save
   * @return bool
   */
  public function promoteFromJuvenile($asset, bool $save = TRUE): bool {
    if ($asset->bundle() !== 'animal') {
      return FALSE;
    }

    if (!$asset->hasField('animal_stage')) {
      return FALSE;
    }

    if ($asset->get('animal_stage')->value !== 'juvenile') {
      return FALSE;
    }

    $sex = $this->getSex($asset);
    $is_sterile = $this->isSterile($asset);

    if ($is_sterile) {
      $new_stage = 'castrated_male';
    }
    elseif ($sex === 'female') {
      $new_stage = 'immature_female';
    }
    elseif ($sex === 'male') {
      $new_stage = 'intact_male';
    }
    else {
      return FALSE;
    }

    $asset->set('animal_stage', $new_stage);

    if ($save) {
      $asset->save();
    }

    $this->logger->info(
      'Animal @id (@name): juvenile → @to.',
      ['@id' => $asset->id(), '@name' => $asset->label(), '@to' => $new_stage]
    );

    return TRUE;
  }

  // ------------------------------------------------------------------ //
  // NATIVE FIELD READERS                                                //
  // (abstract away the 3.x is_castrated / 4.x is_sterile rename)      //
  // ------------------------------------------------------------------ //

  /**
   * Returns 'male', 'female', or NULL from the native sex field.
   */
  public function getSex($asset): ?string {
    if (!$asset->hasField('sex') || $asset->get('sex')->isEmpty()) {
      return NULL;
    }
    return $asset->get('sex')->value;
  }

  /**
   * Returns TRUE if the animal is marked as sterile/castrated.
   * Handles both is_sterile (4.x) and is_castrated (3.x).
   */
  public function isSterile($asset): bool {
    // 4.x field name.
    if ($asset->hasField('is_sterile')) {
      return (bool) $asset->get('is_sterile')->value;
    }
    // 3.x field name.
    if ($asset->hasField('is_castrated')) {
      return (bool) $asset->get('is_castrated')->value;
    }
    return FALSE;
  }

}
