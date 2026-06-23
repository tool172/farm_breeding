<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_breeding\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\asset\Entity\Asset;
use Drupal\log\Entity\Log;
use Drupal\taxonomy\Entity\Term;
use PHPUnit\Framework\Attributes\Group;

/**
 * Kernel tests for farm_breeding.
 *
 * @group farm_breeding
 */
#[Group('farm_breeding')]
class FarmBreedingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    // Drupal core.
    'entity',
    'field',
    'file',
    'geofield',
    'image',
    'options',
    'state_machine',
    'system',
    'taxonomy',
    'text',
    'user',
    'views',
    // farmOS core.
    'asset',
    'log',
    'farm_entity',
    'farm_entity_access',
    'farm_entity_fields',
    'farm_field',
    'farm_id_tag',
    'farm_log',
    'farm_log_asset',
    'farm_parent',
    // farmOS animal.
    'farm_animal',
    'farm_animal_type',
    // farmOS flag (dependency of farm_breeding).
    'farm_flag',
    // farmOS birth (for lifecycle integration).
    'farm_birth',
    // Module under test.
    'farm_breeding',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('asset');
    $this->installEntitySchema('log');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('user');
    $this->installConfig([
      'farm_animal',
      'farm_animal_type',
      'farm_birth',
      'farm_breeding',
      'farm_log_asset',
    ]);
  }

  // ================================================================== //
  // ANIMAL STAGE MANAGER — label derivation                            //
  // ================================================================== //

  public function testAnimalStageLabelsByCattleSpecies(): void {
    $mgr = \Drupal::service('farm_breeding.animal_stage_manager');
    $this->assertEquals('Heifer', $mgr->getLabel('immature_female', 'cattle'));
    $this->assertEquals('Cow',    $mgr->getLabel('mature_female',   'cattle'));
    $this->assertEquals('Bull',   $mgr->getLabel('intact_male',     'cattle'));
    $this->assertEquals('Steer',  $mgr->getLabel('castrated_male',  'cattle'));
    $this->assertEquals('Calf',   $mgr->getLabel('juvenile',        'cattle'));
  }

  public function testAnimalStageLabelsByGoatSpecies(): void {
    $mgr = \Drupal::service('farm_breeding.animal_stage_manager');
    $this->assertEquals('Doeling', $mgr->getLabel('immature_female', 'goat'));
    $this->assertEquals('Doe',     $mgr->getLabel('mature_female',   'goat'));
    $this->assertEquals('Buck',    $mgr->getLabel('intact_male',     'goat'));
    $this->assertEquals('Wether',  $mgr->getLabel('castrated_male',  'goat'));
    $this->assertEquals('Kid',     $mgr->getLabel('juvenile',        'goat'));
  }

  public function testAnimalStageLabelsByPigSpecies(): void {
    $mgr = \Drupal::service('farm_breeding.animal_stage_manager');
    $this->assertEquals('Gilt',    $mgr->getLabel('immature_female', 'pig'));
    $this->assertEquals('Sow',     $mgr->getLabel('mature_female',   'pig'));
    $this->assertEquals('Boar',    $mgr->getLabel('intact_male',     'pig'));
    $this->assertEquals('Barrow',  $mgr->getLabel('castrated_male',  'pig'));
    $this->assertEquals('Piglet',  $mgr->getLabel('juvenile',        'pig'));
  }

  public function testAnimalStageLabelsBySheepSpecies(): void {
    $mgr = \Drupal::service('farm_breeding.animal_stage_manager');
    $this->assertEquals('Ewe lamb', $mgr->getLabel('immature_female', 'sheep'));
    $this->assertEquals('Ewe',      $mgr->getLabel('mature_female',   'sheep'));
    $this->assertEquals('Ram',      $mgr->getLabel('intact_male',     'sheep'));
    $this->assertEquals('Wether',   $mgr->getLabel('castrated_male',  'sheep'));
    $this->assertEquals('Lamb',     $mgr->getLabel('juvenile',        'sheep'));
  }

  public function testAnimalStageFallsBackToNeutralLabel(): void {
    $mgr = \Drupal::service('farm_breeding.animal_stage_manager');
    $label = $mgr->getLabel('immature_female', 'bison');
    $this->assertStringContainsString('heifer', strtolower($label));
  }

  // ================================================================== //
  // ANIMAL STAGE MANAGER — derivation from native fields               //
  // ================================================================== //

  public function testDeriveStageFemaleBornToday(): void {
    $mgr   = \Drupal::service('farm_breeding.animal_stage_manager');
    $asset = $this->createAnimalAsset(['sex' => 'F', 'birthdate' => time()]);
    $this->assertEquals('juvenile', $mgr->deriveStageFromNativeFields($asset));
  }

  public function testDeriveStageFemalePurchased(): void {
    $mgr   = \Drupal::service('farm_breeding.animal_stage_manager');
    $asset = $this->createAnimalAsset(['sex' => 'F']);
    $this->assertEquals('immature_female', $mgr->deriveStageFromNativeFields($asset));
  }

  public function testDeriveStageMaleBornToday(): void {
    $mgr   = \Drupal::service('farm_breeding.animal_stage_manager');
    $asset = $this->createAnimalAsset(['sex' => 'M', 'birthdate' => time()]);
    $this->assertEquals('juvenile', $mgr->deriveStageFromNativeFields($asset));
  }

  public function testDeriveStageMalePurchased(): void {
    $mgr   = \Drupal::service('farm_breeding.animal_stage_manager');
    $asset = $this->createAnimalAsset(['sex' => 'M']);
    $this->assertEquals('intact_male', $mgr->deriveStageFromNativeFields($asset));
  }

  public function testDeriveStageSterile(): void {
    $mgr   = \Drupal::service('farm_breeding.animal_stage_manager');
    $asset = $this->createAnimalAsset(['sex' => 'M', 'is_sterile' => TRUE]);
    $this->assertEquals('castrated_male', $mgr->deriveStageFromNativeFields($asset));
  }

  public function testDeriveStageSexUnknown(): void {
    $mgr   = \Drupal::service('farm_breeding.animal_stage_manager');
    $asset = $this->createAnimalAsset([]);
    $this->assertNull($mgr->deriveStageFromNativeFields($asset));
  }

  // ================================================================== //
  // ANIMAL STAGE MANAGER — advanceToMatureFemale                       //
  // ================================================================== //

  public function testAdvanceToMatureFemaleFromImmature(): void {
    $mgr   = \Drupal::service('farm_breeding.animal_stage_manager');
    $asset = $this->createAnimalAsset(['sex' => 'F', 'animal_stage' => 'immature_female']);

    $result = $mgr->advanceToMatureFemale($asset, TRUE);
    $this->assertTrue($result);

    $asset = \Drupal::entityTypeManager()->getStorage('asset')->load($asset->id());
    $this->assertEquals('mature_female', $asset->get('animal_stage')->value);
  }

  public function testAdvanceToMatureFemaleFromJuvenile(): void {
    $mgr   = \Drupal::service('farm_breeding.animal_stage_manager');
    $asset = $this->createAnimalAsset(['sex' => 'F', 'animal_stage' => 'juvenile']);
    $mgr->advanceToMatureFemale($asset, TRUE);

    $asset = \Drupal::entityTypeManager()->getStorage('asset')->load($asset->id());
    $this->assertEquals('mature_female', $asset->get('animal_stage')->value);
  }

  public function testAdvanceToMatureFemaleNoopForCow(): void {
    $mgr   = \Drupal::service('farm_breeding.animal_stage_manager');
    $asset = $this->createAnimalAsset(['sex' => 'F', 'animal_stage' => 'mature_female']);

    $result = $mgr->advanceToMatureFemale($asset, TRUE);
    $this->assertFalse($result, 'Should return FALSE when no change is needed.');
  }

  public function testAdvanceToMatureFemaleBullNoop(): void {
    $mgr   = \Drupal::service('farm_breeding.animal_stage_manager');
    $asset = $this->createAnimalAsset(['sex' => 'M', 'animal_stage' => 'intact_male']);

    $result = $mgr->advanceToMatureFemale($asset, TRUE);
    $this->assertFalse($result);

    $asset = \Drupal::entityTypeManager()->getStorage('asset')->load($asset->id());
    $this->assertEquals('intact_male', $asset->get('animal_stage')->value);
  }

  // ================================================================== //
  // LIFECYCLE STATE MACHINE                                             //
  // ================================================================== //

  public function testDirectBredToCalvedIsValid(): void {
    $mgr = \Drupal::service('farm_breeding.lifecycle_manager');
    $this->assertTrue($mgr->isValidTransition('bred', 'calved'));
  }

  public function testFullPregCheckPath(): void {
    $mgr = \Drupal::service('farm_breeding.lifecycle_manager');
    $this->assertTrue($mgr->isValidTransition('bred', 'pending_check'));
    $this->assertTrue($mgr->isValidTransition('pending_check', 'pregnant'));
    $this->assertTrue($mgr->isValidTransition('pregnant', 'calved'));
    $this->assertTrue($mgr->isValidTransition('pregnant', 'aborted'));
  }

  public function testTerminalStatesHaveNoTransitions(): void {
    $mgr = \Drupal::service('farm_breeding.lifecycle_manager');
    $this->assertFalse($mgr->isValidTransition('calved', 'bred'));
    $this->assertFalse($mgr->isValidTransition('open', 'pregnant'));
    $this->assertFalse($mgr->isValidTransition('bred', 'aborted'));
  }

  // ================================================================== //
  // BREEDING LOG PRESAVE                                                //
  // ================================================================== //

  public function testDefaultLifecycleStatus(): void {
    $log = $this->createBreedingLog(['breeding_animal_type' => 'cattle']);
    $this->assertEquals('bred', $log->get('breeding_lifecycle_status')->value);
  }

  public function testDueDateAutoCalc(): void {
    $ts  = mktime(0, 0, 0, 1, 1, 2025);
    $log = $this->createBreedingLog(['timestamp' => $ts, 'breeding_animal_type' => 'cattle']);
    $this->assertEquals($ts + (283 * 86400), $log->get('breeding_due_date')->value);
  }

  public function testManualDueDateNotOverwritten(): void {
    $ts  = mktime(0, 0, 0, 1, 1, 2025);
    $due = mktime(0, 0, 0, 10, 15, 2025);
    $log = $this->createBreedingLog([
      'timestamp'            => $ts,
      'breeding_animal_type' => 'cattle',
      'breeding_due_date'    => $due,
    ]);
    $this->assertEquals($due, $log->get('breeding_due_date')->value);
  }

  public function testTurnInDateShiftsEarliestDue(): void {
    $ts      = mktime(0, 0, 0, 4, 1, 2025);
    $turn_in = mktime(0, 0, 0, 3, 15, 2025);
    $log = $this->createBreedingLog([
      'timestamp'             => $ts,
      'breeding_animal_type'  => 'cattle',
      'breeding_turn_in_date' => $turn_in,
    ]);
    $this->assertEquals($turn_in + (279 * 86400), $log->get('breeding_due_date_min')->value);
  }

  // ================================================================== //
  // HELPERS                                                             //
  // ================================================================== //

  /**
   * Creates and saves a breeding log with the given field values.
   */
  protected function createBreedingLog(array $values = []): object {
    $log = Log::create(
      array_merge(['type' => 'breeding', 'status' => 'done'], $values)
    );
    $log->save();
    return $log;
  }

  /**
   * Creates and saves an animal asset with the given field values.
   *
   * A generic 'Cattle' animal_type term is created on first call and reused,
   * satisfying the required entity reference without coupling tests to species.
   */
  protected function createAnimalAsset(array $values = []): object {
    static $term_id = NULL;
    if ($term_id === NULL) {
      $term = Term::create(['name' => 'Cattle', 'vid' => 'animal_type']);
      $term->save();
      $term_id = $term->id();
    }

    $asset = Asset::create(array_merge([
      'type'        => 'animal',
      'name'        => 'Test animal',
      'status'      => 'active',
      'animal_type' => [['target_id' => $term_id]],
    ], $values));
    $asset->save();
    return $asset;
  }

}
