<?php

declare(strict_types=1);

namespace Drupal\farm_breeding\Plugin\QuickForm;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\farm_location\AssetLocationInterface;
use Drupal\farm_quick\Attribute\QuickForm;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Drupal\farm_quick\Traits\QuickLogTrait;
use Drupal\farm_quick\Traits\QuickPrepopulateTrait;
use Drupal\farm_quick\Traits\QuickStringTrait;

/**
 * Breeding quick form.
 */
#[QuickForm(
  id: 'breeding',
  label: new TranslatableMarkup('Breeding'),
  description: new TranslatableMarkup('Record a breeding event.'),
  helpText: new TranslatableMarkup('Use this form to record a breeding event. Choose Individual mode for AI, ET, or confirmed hand matings. Choose Pasture mode to record a turn-in/turn-out period — all female animals currently at the selected location will be linked to the log.'),
  permissions: [
    'create breeding log',
  ],
)]
class Breeding extends QuickFormBase {

  use QuickLogTrait;
  use QuickPrepopulateTrait;
  use QuickStringTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    protected AssetLocationInterface $assetLocation,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $current_user);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $mode = $form_state->getValue('mode', 'individual');

    $form['mode'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Breeding type'),
      '#options'       => [
        'individual' => $this->t('Individual (AI / ET / hand mating)'),
        'pasture'    => $this->t('Pasture / turn-in'),
      ],
      '#default_value' => $mode,
      '#required'      => TRUE,
      '#ajax'          => [
        'callback' => [$this, 'modeCallback'],
        'wrapper'  => 'breeding-fields-wrapper',
      ],
    ];

    $form['fields_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => ['id' => 'breeding-fields-wrapper'],
      '#tree'       => FALSE,
    ];

    if ($mode === 'individual') {
      $this->buildIndividualFields($form['fields_wrapper'], $form_state);
    }
    else {
      $this->buildPastureFields($form['fields_wrapper'], $form_state);
    }

    return $form;
  }

  /**
   * Builds form fields for the individual breeding mode.
   */
  protected function buildIndividualFields(array &$wrapper, FormStateInterface $form_state): void {
    $wrapper['date'] = [
      '#type'          => 'datetime',
      '#title'         => $this->t('Breeding date'),
      '#default_value' => new DrupalDateTime('midnight', $this->currentUser->getTimeZone()),
      '#required'      => TRUE,
    ];

    $prepopulated = $this->getPrepopulatedEntities('asset', $form_state);
    $wrapper['animals'] = [
      '#type'               => 'entity_autocomplete',
      '#title'              => $this->t('Animals'),
      '#description'        => $this->t('Female animals being bred. Start typing a name to search.'),
      '#target_type'        => 'asset',
      '#selection_settings' => [
        'target_bundles' => ['animal'],
        'sort'           => ['field' => 'archived', 'direction' => 'DESC'],
      ],
      '#tags'          => TRUE,
      '#required'      => TRUE,
      '#default_value' => $prepopulated ?: NULL,
    ];

    $wrapper['sire'] = [
      '#type'               => 'entity_autocomplete',
      '#title'              => $this->t('Sire'),
      '#description'        => $this->t('The sire used for this breeding.'),
      '#target_type'        => 'asset',
      '#selection_settings' => [
        'target_bundles' => ['animal'],
        'sort'           => ['field' => 'archived', 'direction' => 'DESC'],
      ],
    ];

    $wrapper['breeding_method'] = [
      '#type'         => 'select',
      '#title'        => $this->t('Breeding method'),
      '#options'      => [
        'natural' => $this->t('Natural service'),
        'ai'      => $this->t('Artificial insemination (AI)'),
        'et'      => $this->t('Embryo transfer (ET)'),
        'other'   => $this->t('Other'),
      ],
      '#empty_option' => $this->t('— Select —'),
    ];

    $wrapper['lot_id'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Semen / embryo lot ID'),
      '#description' => $this->t('Straw number, batch ID, or tank location (optional).'),
    ];

    $wrapper['notes'] = [
      '#type'   => 'text_format',
      '#title'  => $this->t('Notes'),
      '#format' => 'default',
    ];
  }

  /**
   * Builds form fields for the pasture / turn-in breeding mode.
   */
  protected function buildPastureFields(array &$wrapper, FormStateInterface $form_state): void {
    $wrapper['turn_in_date'] = [
      '#type'          => 'datetime',
      '#title'         => $this->t('Turn-in date'),
      '#description'   => $this->t('Date females were placed with the sire. Sets the earliest possible conception date.'),
      '#default_value' => new DrupalDateTime('midnight', $this->currentUser->getTimeZone()),
      '#required'      => TRUE,
    ];

    $wrapper['turn_out_date'] = [
      '#type'        => 'datetime',
      '#title'       => $this->t('Turn-out date'),
      '#description' => $this->t('Date the sire was removed (optional). Sets the latest possible conception date.'),
    ];

    $wrapper['location'] = [
      '#type'               => 'entity_autocomplete',
      '#title'              => $this->t('Breeding location'),
      '#description'        => $this->t('The pasture or location where the breeding group is housed. Female animals currently at this location will be linked to the log.'),
      '#target_type'        => 'asset',
      '#selection_settings' => [
        'target_bundles' => ['land'],
        'sort'           => ['field' => 'archived', 'direction' => 'DESC'],
      ],
      '#ajax' => [
        'callback' => [$this, 'femaleCountCallback'],
        'wrapper'  => 'female-count-wrapper',
        'event'    => 'autocompleteclose change',
      ],
    ];

    // Female count display — updated via AJAX when location changes.
    $wrapper['female_count_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => ['id' => 'female-count-wrapper'],
    ];
    $location_id = $form_state->getValue('location');
    if (!empty($location_id)) {
      $count = $this->countFemalesAtLocation((int) $location_id);
      $wrapper['female_count_wrapper']['female_count'] = [
        '#markup' => $this->formatPlural(
          $count,
          '<strong>1 female animal</strong> currently at this location will be linked to the log.',
          '<strong>@count female animals</strong> currently at this location will be linked to the log.',
        ),
      ];
    }

    $wrapper['sire'] = [
      '#type'               => 'entity_autocomplete',
      '#title'              => $this->t('Sire'),
      '#description'        => $this->t('The sire turned in with the females.'),
      '#target_type'        => 'asset',
      '#selection_settings' => [
        'target_bundles' => ['animal'],
        'sort'           => ['field' => 'archived', 'direction' => 'DESC'],
      ],
    ];

    $wrapper['notes'] = [
      '#type'   => 'text_format',
      '#title'  => $this->t('Notes'),
      '#format' => 'default',
    ];
  }

  /**
   * AJAX callback: rebuilds the mode-specific fields section.
   */
  public function modeCallback(array $form, FormStateInterface $form_state): array {
    return $form['fields_wrapper'];
  }

  /**
   * AJAX callback: updates the female count display for the selected location.
   */
  public function femaleCountCallback(array $form, FormStateInterface $form_state): array {
    return $form['fields_wrapper']['female_count_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('mode') !== 'pasture') {
      return;
    }

    $turn_in  = $form_state->getValue('turn_in_date');
    $turn_out = $form_state->getValue('turn_out_date');

    if ($turn_in instanceof DrupalDateTime && $turn_out instanceof DrupalDateTime
        && $turn_out->getTimestamp() <= $turn_in->getTimestamp()) {
      $form_state->setError(
        $form['fields_wrapper']['turn_out_date'],
        $this->t('Turn-out date must be after the turn-in date.')
      );
    }

    $location_id = $form_state->getValue('location');
    if (!empty($location_id)) {
      $count = $this->countFemalesAtLocation((int) $location_id);
      if ($count === 0) {
        $location = $this->entityTypeManager->getStorage('asset')->load($location_id);
        $location_name = $location ? $location->label() : $this->t('the selected location');
        $form_state->setError(
          $form['fields_wrapper']['location'],
          $this->t('No active female animals were found in @location. Select a different location or use Individual mode.', [
            '@location' => $location_name,
          ])
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('mode') === 'individual') {
      $this->submitIndividual($form_state);
    }
    else {
      $this->submitPasture($form_state);
    }
  }

  /**
   * Creates a breeding log for the individual mode.
   */
  protected function submitIndividual(FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Datetime\DrupalDateTime $date */
    $date = $form_state->getValue('date');

    // Load animal entities to generate the log name.
    $animal_items = $form_state->getValue('animals') ?? [];
    $animals = [];
    foreach ($animal_items as $item) {
      if (!empty($item['target_id'])) {
        $asset = $this->entityTypeManager->getStorage('asset')->load($item['target_id']);
        if ($asset) {
          $animals[] = $asset;
        }
      }
    }

    $values = [
      'type'      => 'breeding',
      'timestamp' => $date->getTimestamp(),
      'asset'     => $form_state->getValue('animals'),
      'notes'     => $form_state->getValue('notes'),
      'status'    => 'done',
      'name'      => !empty($animals)
        ? $this->t('Breeding: @animals', ['@animals' => Markup::create($this->entityLabelsSummary($animals))])
        : $this->t('Breeding'),
    ];

    if ($sire_id = $form_state->getValue('sire')) {
      $values['breeding_sire'] = $sire_id;
    }
    if ($method = $form_state->getValue('breeding_method')) {
      $values['breeding_method'] = $method;
    }
    if ($lot_id = $form_state->getValue('lot_id')) {
      $values['breeding_lot_id'] = $lot_id;
    }

    $this->createLog($values);
  }

  /**
   * Creates a breeding log for the pasture / turn-in mode.
   */
  protected function submitPasture(FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Datetime\DrupalDateTime $turn_in */
    $turn_in = $form_state->getValue('turn_in_date');

    // Load female animals from the location before building the log.
    $location_id = $form_state->getValue('location');
    $location_name = $this->t('pasture');
    $females = [];
    if (!empty($location_id)) {
      $location = $this->entityTypeManager->getStorage('asset')->load($location_id);
      if ($location) {
        $location_name = $location->label();
        $all_assets = $this->assetLocation->getAssetsByLocation([$location]);
        $females = array_values(array_filter($all_assets, function ($asset) {
          return $asset->bundle() === 'animal'
            && $asset->hasField('sex')
            && $asset->get('sex')->value === 'F';
        }));
      }
    }

    // Validation should prevent reaching here with zero females, but guard
    // defensively — do not create an empty breeding log.
    if (empty($females)) {
      $this->messenger()->addWarning($this->t('No active female animals were found at @location. No breeding log was created.', [
        '@location' => $location_name,
      ]));
      return;
    }

    $values = [
      'type'                  => 'breeding',
      'timestamp'             => $turn_in->getTimestamp(),
      'breeding_turn_in_date' => $turn_in->getTimestamp(),
      'breeding_method'       => 'natural',
      'asset'                 => $females,
      'notes'                 => $form_state->getValue('notes'),
      'status'                => 'done',
    ];

    // Explicitly set the boolean — TRUE for a herd, FALSE for a single animal.
    $values['is_group_assignment'] = count($females) > 1;

    /** @var \Drupal\Core\Datetime\DrupalDateTime|null $turn_out */
    $turn_out = $form_state->getValue('turn_out_date');
    if ($turn_out instanceof DrupalDateTime) {
      $values['breeding_turn_out_date'] = $turn_out->getTimestamp();
    }

    if ($sire_id = $form_state->getValue('sire')) {
      $values['breeding_sire'] = $sire_id;
    }

    if (isset($location)) {
      $values['location'] = [$location];
    }

    $values['name'] = $this->t('Breeding: @location turn-in @date', [
      '@location' => Markup::create((string) $location_name),
      '@date'     => $turn_in->format('Y-m-d'),
    ]);

    $this->createLog($values);
  }

  /**
   * Counts female animals currently at a land asset location.
   */
  protected function countFemalesAtLocation(int $location_id): int {
    $location = $this->entityTypeManager->getStorage('asset')->load($location_id);
    if (!$location) {
      return 0;
    }
    $count = 0;
    foreach ($this->assetLocation->getAssetsByLocation([$location]) as $asset) {
      if ($asset->bundle() === 'animal'
          && $asset->hasField('sex')
          && $asset->get('sex')->value === 'F') {
        $count++;
      }
    }
    return $count;
  }

}
