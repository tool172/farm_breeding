<?php

namespace Drupal\farm_breeding\Plugin\Log\LogType;

use Drupal\farm_entity\Plugin\Log\LogType\FarmLogType;

/**
 * Provides the pregnancy check log type.
 *
 * @LogType(
 *   id = "pregnancy_check",
 *   label = @Translation("Pregnancy Check"),
 * )
 */
class PregnancyCheck extends FarmLogType {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = parent::buildFieldDefinitions();
    $f = \Drupal::service('farm_field.factory');

    $fields['pregnancy_status'] = $f->bundleFieldDefinition([
      'type'        => 'list_string',
      'label'       => t('Pregnancy status'),
      'description' => t('Positive → linked breeding log moves to Confirmed pregnant. Negative → Open. Uncertain and EEL require manual follow-up.'),
      'allowed_values' => [
        'positive'             => t('Positive (pregnant)'),
        'negative'             => t('Negative / open'),
        'uncertain'            => t('Uncertain — recheck'),
        'early_embryonic_loss' => t('Early embryonic loss (EEL)'),
      ],
      'weight' => ['form' => 10, 'view' => 10],
    ]);

    $fields['pregnancy_check_method'] = $f->bundleFieldDefinition([
      'type'        => 'list_string',
      'label'       => t('Check method'),
      'allowed_values' => [
        'rectal_palpation' => t('Rectal palpation'),
        'ultrasound'       => t('Ultrasound'),
        'blood_test'       => t('Blood test (progesterone / PAG)'),
        'milk_test'        => t('Milk test'),
        'visual'           => t('Visual observation'),
        'other'            => t('Other'),
      ],
      'weight' => ['form' => 20, 'view' => 20],
    ]);

    $fields['pregnancy_days'] = $f->bundleFieldDefinition([
      'type'        => 'integer',
      'label'       => t('Estimated days pregnant'),
      'description' => t('Estimated fetal age at the time of this check.'),
      'weight' => ['form' => 30, 'view' => 30],
    ]);

    $fields['pregnancy_fetus_count'] = $f->bundleFieldDefinition([
      'type'        => 'integer',
      'label'       => t('Number of fetuses'),
      'description' => t('1 = single, 2 = twins, etc.'),
      'weight' => ['form' => 40, 'view' => 40],
    ]);

    $fields['pregnancy_checked_by'] = $f->bundleFieldDefinition([
      'type'        => 'string',
      'label'       => t('Checked by'),
      'description' => t('Veterinarian or technician who performed the check.'),
      'weight' => ['form' => 50, 'view' => 50],
    ]);

    // Linking back to a breeding log is OPTIONAL — not required.
    $fields['pregnancy_breeding_log'] = $f->bundleFieldDefinition([
      'type'          => 'entity_reference',
      'label'         => t('Associated breeding log'),
      'description'   => t('Optional. When set, saving this check will automatically update the breeding log\'s lifecycle status.'),
      'target_type'   => 'log',
      'target_bundle' => 'breeding',
      'multiple'      => FALSE,
      'weight' => ['form' => 55, 'view' => 55],
    ]);

    $fields['pregnancy_revised_due_date'] = $f->bundleFieldDefinition([
      'type'        => 'timestamp',
      'label'       => t('Revised due date'),
      'description' => t('If the vet revises the expected due date, enter it here and it will be saved back to the linked breeding log.'),
      'weight' => ['form' => 60, 'view' => 60],
    ]);

    return $fields;
  }

}
