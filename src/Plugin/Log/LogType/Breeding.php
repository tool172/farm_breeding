<?php

namespace Drupal\farm_breeding\Plugin\Log\LogType;

use Drupal\farm_entity\Plugin\Log\LogType\FarmLogType;

/**
 * Provides the breeding log type.
 *
 * @LogType(
 *   id = "breeding",
 *   label = @Translation("Breeding"),
 * )
 */
class Breeding extends FarmLogType {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = parent::buildFieldDefinitions();
    $f = \Drupal::service('farm_field.factory');

    // ---- LIFECYCLE -------------------------------------------------------

    $fields['breeding_lifecycle_status'] = $f->bundleFieldDefinition([
      'type'        => 'list_string',
      'label'       => t('Breeding status'),
      'description' => t('Current lifecycle state. Updates automatically when pregnancy check and birth logs are recorded. Pregnancy checking is optional — a birth log advances this directly to Calved from any open state.'),
      'allowed_values' => [
        'bred'          => t('Bred'),
        'pending_check' => t('Pending pregnancy check'),
        'pregnant'      => t('Confirmed pregnant'),
        'calved'        => t('Calved'),
        'open'          => t('Open (not pregnant)'),
        'aborted'       => t('Aborted / pregnancy loss'),
      ],
      'weight' => ['form' => 5, 'view' => 5],
    ]);

    // ---- SPECIES & SIRE --------------------------------------------------

    $fields['breeding_animal_type'] = $f->bundleFieldDefinition([
      'type'        => 'list_string',
      'label'       => t('Species / breed group'),
      'description' => t('Inferred automatically from the dam asset\'s species term if left blank. Drives gestation length and due-date calculation.'),
      'allowed_values_function' => 'farm_breeding_animal_type_options',
      'weight' => ['form' => 10, 'view' => 10],
    ]);

    $fields['breeding_sire'] = $f->bundleFieldDefinition([
      'type'          => 'entity_reference',
      'label'         => t('Sire'),
      'description'   => t('The sire used for this breeding. Used for sire performance reporting.'),
      'target_type'   => 'asset',
      'target_bundle' => 'animal',
      'multiple'      => FALSE,
      'weight' => ['form' => 15, 'view' => 15],
    ]);

    // ---- BREEDING METHOD -------------------------------------------------

    $fields['breeding_method'] = $f->bundleFieldDefinition([
      'type'        => 'list_string',
      'label'       => t('Breeding method'),
      'allowed_values' => [
        'natural' => t('Natural service'),
        'ai'      => t('Artificial insemination (AI)'),
        'et'      => t('Embryo transfer (ET)'),
        'other'   => t('Other'),
      ],
      'weight' => ['form' => 20, 'view' => 20],
    ]);

    $fields['breeding_lot_id'] = $f->bundleFieldDefinition([
      'type'        => 'string',
      'label'       => t('Semen / embryo lot ID'),
      'description' => t('Straw number, batch ID, or tank location.'),
      'weight' => ['form' => 25, 'view' => 25],
    ]);

    $fields['breeding_estrus_method'] = $f->bundleFieldDefinition([
      'type'        => 'string',
      'label'       => t('Estrus detection method'),
      'description' => t('How heat was detected (e.g., visual, Estrotect patch, electronic sensor).'),
      'weight' => ['form' => 30, 'view' => 30],
    ]);

    // ---- PASTURE BREEDING WINDOW -----------------------------------------

    $fields['breeding_turn_in_date'] = $f->bundleFieldDefinition([
      'type'        => 'timestamp',
      'label'       => t('Turn-in date'),
      'description' => t('Date females were placed with the sire (natural service). Sets the EARLIEST possible conception date — the calving window minimum is calculated from this date.'),
      'weight' => ['form' => 35, 'view' => 35],
    ]);

    $fields['breeding_turn_out_date'] = $f->bundleFieldDefinition([
      'type'        => 'timestamp',
      'label'       => t('Turn-out date'),
      'description' => t('Date sire was removed. Sets the LATEST possible conception date — the calving window maximum is calculated from this date.'),
      'weight' => ['form' => 37, 'view' => 37],
    ]);

    // ---- CALCULATED DUE DATE FIELDS (auto-populated on save) -------------

    $fields['breeding_gestation_days'] = $f->bundleFieldDefinition([
      'type'        => 'integer',
      'label'       => t('Typical gestation (days)'),
      'description' => t('Auto-populated from species setting. For reference.'),
      'weight' => ['form' => 40, 'view' => 40],
    ]);

    $fields['breeding_due_date'] = $f->bundleFieldDefinition([
      'type'        => 'timestamp',
      'label'       => t('Expected due date'),
      'description' => t('Auto-calculated from breeding date + typical gestation. Override manually if needed.'),
      'weight' => ['form' => 45, 'view' => 45],
    ]);

    $fields['breeding_due_date_min'] = $f->bundleFieldDefinition([
      'type'        => 'timestamp',
      'label'       => t('Earliest possible due date'),
      'description' => t('Based on minimum gestation. Calculated from turn-in date if provided, otherwise from breeding date.'),
      'weight' => ['form' => 47, 'view' => 47],
    ]);

    $fields['breeding_due_date_max'] = $f->bundleFieldDefinition([
      'type'        => 'timestamp',
      'label'       => t('Latest possible due date'),
      'description' => t('Based on maximum gestation. Calculated from turn-out date if provided, otherwise from breeding date.'),
      'weight' => ['form' => 49, 'view' => 49],
    ]);

    $fields['breeding_check_due_date'] = $f->bundleFieldDefinition([
      'type'        => 'timestamp',
      'label'       => t('Pregnancy check due date'),
      'description' => t('Suggested date to run a preg check (breeding date + species check interval). Auto-calculated; leave blank if you do not preg check.'),
      'weight' => ['form' => 51, 'view' => 51],
    ]);

    // ---- OUTCOME ---------------------------------------------------------

    $fields['breeding_calving_date'] = $f->bundleFieldDefinition([
      'type'        => 'timestamp',
      'label'       => t('Actual calving date'),
      'description' => t('Set automatically from the birth log timestamp when a birth is recorded.'),
      'weight' => ['form' => 60, 'view' => 60],
    ]);

    // ---- v1.1 SCHEMA STUBS (hidden until v1.1) ---------------------------

    $fields['breeding_birth_weight'] = $f->bundleFieldDefinition([
      'type'   => 'decimal',
      'label'  => t('Birth weight'),
      'weight' => ['form' => 70, 'view' => 70],
      'hidden' => TRUE,
    ]);

    $fields['breeding_calving_ease'] = $f->bundleFieldDefinition([
      'type'  => 'list_string',
      'label' => t('Calving ease'),
      'allowed_values' => [
        '1' => t('1 — Unassisted'),
        '2' => t('2 — Minor assist'),
        '3' => t('3 — Major assist'),
        '4' => t('4 — Malpresentation'),
        '5' => t('5 — Caesarean'),
      ],
      'weight' => ['form' => 72, 'view' => 72],
      'hidden' => TRUE,
    ]);

    $fields['breeding_twin_flag'] = $f->bundleFieldDefinition([
      'type'   => 'boolean',
      'label'  => t('Twins'),
      'weight' => ['form' => 74, 'view' => 74],
      'hidden' => TRUE,
    ]);

    $fields['breeding_registration_number'] = $f->bundleFieldDefinition([
      'type'   => 'string',
      'label'  => t('Registration number'),
      'weight' => ['form' => 76, 'view' => 76],
      'hidden' => TRUE,
    ]);

    return $fields;
  }

}
