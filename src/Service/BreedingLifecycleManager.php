<?php

namespace Drupal\farm_breeding\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages breeding lifecycle state transitions and dam stage promotion.
 *
 * STATE MACHINE
 * =============
 *  bred ──────────────────────────────────────────► calved  (direct: no preg check)
 *  bred ──────────────────────────────────────────► open
 *  bred ──────────────────► pending_check
 *                                │
 *                           pregnant ───────────── ► calved
 *                           pregnant ────────────── ► aborted
 *                                │
 *                           open
 *
 * Pregnancy checking is OPTIONAL.  A birth log advances directly from any
 * open state (bred / pending_check / pregnant) to calved.
 *
 * DAM STAGE PROMOTION
 * ===================
 * Uses AnimalStageManager.advanceToMatureFemale() — species-neutral, reads
 * the native farmOS sex field rather than relying on cattle-specific flags.
 */
class BreedingLifecycleManager {

  const TRANSITIONS = [
    'bred'          => ['pending_check', 'pregnant', 'calved', 'open'],
    'pending_check' => ['pregnant', 'open'],
    'pregnant'      => ['calved', 'aborted'],
    'open'          => [],
    'aborted'       => [],
    'calved'        => [],
  ];

  const STATE_LABELS = [
    'bred'          => 'Bred',
    'pending_check' => 'Pending pregnancy check',
    'pregnant'      => 'Confirmed pregnant',
    'calved'        => 'Calved',
    'open'          => 'Open (not pregnant)',
    'aborted'       => 'Aborted / pregnancy loss',
  ];

  const OPEN_TO_BIRTH_STATES = ['bred', 'pending_check', 'pregnant'];

  protected EntityTypeManagerInterface $entityTypeManager;
  protected GestationCalculator $gestationCalculator;
  protected SpeciesResolver $speciesResolver;
  protected AnimalStageManager $animalStageManager;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface $logger;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    GestationCalculator $gestation_calculator,
    SpeciesResolver $species_resolver,
    AnimalStageManager $animal_stage_manager,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger
  ) {
    $this->entityTypeManager   = $entity_type_manager;
    $this->gestationCalculator = $gestation_calculator;
    $this->speciesResolver     = $species_resolver;
    $this->animalStageManager  = $animal_stage_manager;
    $this->configFactory       = $config_factory;
    $this->logger              = $logger;
  }

  // ------------------------------------------------------------------ //
  // STATE MACHINE                                                       //
  // ------------------------------------------------------------------ //

  public function getStateLabels(): array {
    return self::STATE_LABELS;
  }

  public function getAllowedTransitions(string $from): array {
    return self::TRANSITIONS[$from] ?? [];
  }

  public function isValidTransition(string $from, string $to): bool {
    return in_array($to, self::TRANSITIONS[$from] ?? [], TRUE);
  }

  public function transition($breeding_log, string $new_state, bool $save = TRUE): bool {
    if ($breeding_log->bundle() !== 'breeding') {
      return FALSE;
    }

    $current = $breeding_log->get('breeding_lifecycle_status')->value ?? 'bred';

    if (!$this->isValidTransition($current, $new_state)) {
      $this->logger->warning(
        'Invalid breeding lifecycle transition on log @id: @from → @to',
        ['@id' => $breeding_log->id(), '@from' => $current, '@to' => $new_state]
      );
      return FALSE;
    }

    $breeding_log->set('breeding_lifecycle_status', $new_state);
    if ($save) {
      $breeding_log->save();
    }

    $this->logger->info(
      'Breeding log @id: @from → @to',
      ['@id' => $breeding_log->id(), '@from' => $current, '@to' => $new_state]
    );

    return TRUE;
  }

  // ------------------------------------------------------------------ //
  // EVENT HANDLERS                                                      //
  // ------------------------------------------------------------------ //

  /**
   * Handles a saved Pregnancy Check log.
   *
   * positive  → breeding log advances to 'pregnant'
   * negative  → breeding log advances to 'open'
   * uncertain → no automatic transition (requires human decision)
   * eel       → no automatic transition (requires human decision)
   *
   * If a revised due date is provided by the vet, it overwrites the
   * calculated value on the breeding log.
   */
  public function handlePregnancyCheckSaved($preg_check_log): void {
    if ($preg_check_log->bundle() !== 'pregnancy_check') {
      return;
    }

    $breeding_log = $this->getLinkedBreedingLog($preg_check_log);
    if (!$breeding_log) {
      return;
    }

    $status = $preg_check_log->get('pregnancy_status')->value;
    $new_state = match($status) {
      'positive' => 'pregnant',
      'negative' => 'open',
      default    => NULL,
    };

    if ($new_state !== NULL) {
      $this->transition($breeding_log, $new_state);
    }

    // Vet-revised due date overwrites the auto-calculated value.
    $revised_due = $preg_check_log->get('pregnancy_revised_due_date')->value;
    if (!empty($revised_due)) {
      $breeding_log->set('breeding_due_date', $revised_due);
      $breeding_log->save();
    }
  }

  /**
   * Handles a saved Birth log.
   *
   * 1. Finds the most recent open breeding log for the dam(s).
   * 2. Transitions it to 'calved' from any open state (no preg check needed).
   * 3. Stores the actual calving date.
   * 4. Advances the dam's animal_stage to 'mature_female' via AnimalStageManager.
   *    This is species-neutral: reads native sex field, works for cattle/goats/pigs.
   */
  public function handleBirthLogSaved($birth_log): void {
    if ($birth_log->bundle() !== 'birth') {
      return;
    }

    $asset_ids = $this->collectAssetIds($birth_log);
    if (empty($asset_ids)) {
      return;
    }

    $breeding_log = $this->findOpenBreedingLog($asset_ids);
    if (!$breeding_log) {
      return;
    }

    $this->transition($breeding_log, 'calved', FALSE);
    $breeding_log->set('breeding_calving_date', $birth_log->get('timestamp')->value);
    $breeding_log->save();

    // Advance dam(s) to mature_female using species-neutral stage logic.
    $settings = $this->configFactory->get('farm_breeding.settings');
    if ($settings->get('auto_advance_dam_stage') !== FALSE) {
      $this->advanceDamStage($asset_ids);
    }
  }

  // ------------------------------------------------------------------ //
  // DAM STAGE ADVANCEMENT                                              //
  // ------------------------------------------------------------------ //

  /**
   * Advances each dam asset from juvenile/immature_female → mature_female.
   *
   * Uses AnimalStageManager which reads the native farmOS sex field.
   * Works for cattle, goats, sheep, pigs — any species with sex set.
   * Safe to call on already-mature_female animals (no-op).
   *
   * @param int[] $asset_ids
   */
  public function advanceDamStage(array $asset_ids): void {
    $assets = $this->entityTypeManager->getStorage('asset')->loadMultiple($asset_ids);

    foreach ($assets as $asset) {
      if ($asset->bundle() !== 'animal') {
        continue;
      }
      $this->animalStageManager->advanceToMatureFemale($asset);
    }
  }

  // ------------------------------------------------------------------ //
  // PRIVATE HELPERS                                                     //
  // ------------------------------------------------------------------ //

  protected function collectAssetIds($log): array {
    $ids = [];
    foreach ($log->get('asset') as $ref) {
      if (!empty($ref->target_id)) {
        $ids[] = (int) $ref->target_id;
      }
    }
    return $ids;
  }

  protected function findOpenBreedingLog(array $asset_ids) {
    $storage = $this->entityTypeManager->getStorage('log');
    $query = $storage->getQuery()
      ->condition('type', 'breeding')
      ->condition('breeding_lifecycle_status', self::OPEN_TO_BIRTH_STATES, 'IN')
      ->condition('asset', $asset_ids, 'IN')
      ->sort('timestamp', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $ids = $query->execute();
    if (empty($ids)) {
      return NULL;
    }
    return $storage->load(reset($ids));
  }

  protected function getLinkedBreedingLog($preg_check_log) {
    $field = $preg_check_log->get('pregnancy_breeding_log');
    if ($field->isEmpty()) {
      return NULL;
    }
    return $field->entity;
  }

}
