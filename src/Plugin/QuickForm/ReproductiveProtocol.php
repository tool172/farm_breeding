<?php

declare(strict_types=1);

namespace Drupal\farm_breeding\Plugin\QuickForm;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\farm_location\AssetLocationInterface;
use Drupal\farm_quick\Attribute\QuickForm;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Drupal\farm_quick\Traits\QuickAssetTrait;
use Drupal\farm_quick\Traits\QuickLogTrait;
use Drupal\farm_quick\Traits\QuickStringTrait;

/**
 * Reproductive Protocol quick form.
 */
#[QuickForm(
  id: 'reproductive_protocol',
  label: new TranslatableMarkup('Reproductive Protocol'),
  description: new TranslatableMarkup('Schedule a reproductive synchronization protocol.'),
  helpText: new TranslatableMarkup('Use this form to schedule a synchronization protocol (e.g. 5-Day CIDR + AI). All events are generated as logs. Future events are created as pending; past events are marked done.'),
  permissions: [
    'create breeding log',
    'create activity log',
  ],
)]
class ReproductiveProtocol extends QuickFormBase {

  use QuickAssetTrait;
  use QuickLogTrait;
  use QuickStringTrait;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    protected AssetLocationInterface $assetLocation,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $current_user);
  }

  /**
   * Returns all enabled protocols from config.
   */
  protected function getEnabledProtocols(): array {
    $all = $this->configFactory->get('farm_breeding.protocols')->get('protocols') ?? [];
    return array_filter($all, fn($p) => !empty($p['enabled']));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $protocols = $this->getEnabledProtocols();

    if (empty($protocols)) {
      $form['no_protocols'] = [
        '#markup' => $this->t('No reproductive protocols are enabled. <a href="/farm/settings/breeding">Configure protocols</a>.'),
      ];
      return $form;
    }

    $protocol_options = array_map(fn($p) => $p['label'], $protocols);

    $selected_id = $form_state->getValue('protocol') ?? array_key_first($protocol_options);
    $method = $protocols[$selected_id]['method'] ?? 'ai';

    $form['protocol'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Protocol'),
      '#description'   => $this->t('Select the synchronization protocol to schedule.'),
      '#options'       => $protocol_options,
      '#default_value' => $selected_id,
      '#required'      => TRUE,
      '#ajax'          => [
        'callback' => [$this, 'protocolCallback'],
        'wrapper'  => 'rp-fields-wrapper',
      ],
    ];

    $form['start_date'] = [
      '#type'          => 'datetime',
      '#title'         => $this->t('Day 0 date and time'),
      '#description'   => $this->t('Date and time of the first protocol event. All subsequent events are calculated relative to this.'),
      '#default_value' => new DrupalDateTime('midnight', $this->currentUser->getTimeZone()),
      '#required'      => TRUE,
    ];

    $form['rp_fields_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => ['id' => 'rp-fields-wrapper'],
      '#tree'       => FALSE,
    ];

    $animal_source = $form_state->getValue('animal_source', 'individual');

    $form['rp_fields_wrapper']['animal_source'] = [
      '#type'          => 'radios',
      '#title'         => $this->t('Enroll animals by'),
      '#options'       => [
        'individual' => $this->t('Selecting individual animals'),
        'location'   => $this->t('All females at a location'),
      ],
      '#default_value' => $animal_source,
      '#required'      => TRUE,
      '#ajax'          => [
        'callback' => [$this, 'animalSourceCallback'],
        'wrapper'  => 'rp-animals-wrapper',
      ],
    ];

    $form['rp_fields_wrapper']['animals_wrapper'] = [
      '#type'       => 'container',
      '#attributes' => ['id' => 'rp-animals-wrapper'],
    ];

    if ($animal_source === 'individual') {
      $form['rp_fields_wrapper']['animals_wrapper']['animals'] = [
        '#type'               => 'entity_autocomplete',
        '#title'              => $this->t('Female animals'),
        '#description'        => $this->t('Animals to enroll in this protocol run.'),
        '#target_type'        => 'asset',
        '#selection_settings' => [
          'target_bundles' => ['animal'],
          'sort'           => ['field' => 'archived', 'direction' => 'DESC'],
        ],
        '#tags'     => TRUE,
        '#required' => TRUE,
      ];
    }
    else {
      $form['rp_fields_wrapper']['animals_wrapper']['location'] = [
        '#type'               => 'entity_autocomplete',
        '#title'              => $this->t('Location'),
        '#description'        => $this->t('All active female animals at this location will be enrolled in the protocol.'),
        '#target_type'        => 'asset',
        '#selection_settings' => [
          'target_bundles' => ['land'],
          'sort'           => ['field' => 'archived', 'direction' => 'DESC'],
        ],
        '#required' => TRUE,
        '#ajax'     => [
          'callback' => [$this, 'locationCallback'],
          'wrapper'  => 'rp-female-count-wrapper',
          'event'    => 'autocompleteclose change',
        ],
      ];

      $form['rp_fields_wrapper']['animals_wrapper']['female_count_wrapper'] = [
        '#type'       => 'container',
        '#attributes' => ['id' => 'rp-female-count-wrapper'],
      ];

      $location_id = $form_state->getValue('location');
      if (!empty($location_id)) {
        $count = $this->countFemalesAtLocation((int) $location_id);
        $form['rp_fields_wrapper']['animals_wrapper']['female_count_wrapper']['female_count'] = [
          '#markup' => $this->formatPlural(
            $count,
            '<strong>1 female animal</strong> at this location will be enrolled.',
            '<strong>@count female animals</strong> at this location will be enrolled.',
          ),
        ];
      }
    }

    $sire_label       = ($method === 'et') ? $this->t('Donor cow') : $this->t('Sire');
    $sire_description = ($method === 'et')
      ? $this->t('The embryo donor cow for this protocol run.')
      : $this->t('The sire (bull or straw source) for this protocol run.');

    $form['rp_fields_wrapper']['sire_donor'] = [
      '#type'               => 'entity_autocomplete',
      '#title'              => $sire_label,
      '#description'        => $sire_description,
      '#target_type'        => 'asset',
      '#selection_settings' => [
        'target_bundles' => ['animal'],
        'sort'           => ['field' => 'archived', 'direction' => 'DESC'],
      ],
    ];

    $form['rp_fields_wrapper']['lot_id'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Semen / embryo lot ID'),
      '#description' => $this->t('Straw number, batch ID, or tank location (optional).'),
    ];

    $form['rp_fields_wrapper']['notes'] = [
      '#type'   => 'text_format',
      '#title'  => $this->t('Notes'),
      '#format' => 'default',
    ];

    return $form;
  }

  /**
   * AJAX callback: rebuilds the protocol-dependent fields wrapper.
   */
  public function protocolCallback(array $form, FormStateInterface $form_state): array {
    return $form['rp_fields_wrapper'];
  }

  /**
   * AJAX callback: rebuilds the animals vs. location sub-section.
   */
  public function animalSourceCallback(array $form, FormStateInterface $form_state): array {
    return $form['rp_fields_wrapper']['animals_wrapper'];
  }

  /**
   * AJAX callback: updates the female count display for the selected location.
   */
  public function locationCallback(array $form, FormStateInterface $form_state): array {
    return $form['rp_fields_wrapper']['animals_wrapper']['female_count_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('animal_source') !== 'location') {
      return;
    }
    $location_id = $form_state->getValue('location');
    if (empty($location_id)) {
      return;
    }
    $count = $this->countFemalesAtLocation((int) $location_id);
    if ($count === 0) {
      $location = $this->entityTypeManager->getStorage('asset')->load($location_id);
      $location_name = $location ? $location->label() : $this->t('the selected location');
      $form_state->setError(
        $form['rp_fields_wrapper']['animals_wrapper']['location'],
        $this->t('No active female animals were found at @location.', ['@location' => $location_name])
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $protocols = $this->getEnabledProtocols();
    $protocol_id = $form_state->getValue('protocol');
    $protocol = $protocols[$protocol_id] ?? NULL;

    if (!$protocol) {
      $this->messenger()->addError($this->t('Invalid protocol selected.'));
      return;
    }

    $animals = $this->resolveAnimals($form_state);
    if (empty($animals)) {
      $this->messenger()->addWarning($this->t('No female animals found. No logs were created.'));
      return;
    }

    /** @var \Drupal\Core\Datetime\DrupalDateTime $start_date */
    $start_date = $form_state->getValue('start_date');
    $start_ts   = $start_date->getTimestamp();
    $now        = time();
    $method     = $protocol['method'] ?? 'ai';
    $sire_id    = $form_state->getValue('sire_donor');
    $lot_id     = $form_state->getValue('lot_id');
    $notes      = $form_state->getValue('notes');

    // Create a Group asset to link all generated logs for this protocol run.
    $run_label   = (string) $this->t('@protocol — @date', [
      '@protocol' => $protocol['label'],
      '@date'     => $start_date->format('Y-m-d'),
    ]);
    $group_asset = $this->createAsset([
      'type'   => 'group',
      'name'   => $run_label,
      'status' => 'active',
    ]);

    // Track the breeding log so the pregnancy check can reference it.
    $breeding_log = NULL;

    foreach ($protocol['events'] as $event) {
      $event_ts = $start_ts + ($event['day'] * 86400) + ($event['hour'] * 3600);
      $status   = $event_ts > $now ? 'pending' : 'done';
      $log_type = $event['log_type'];

      $log_values = [
        'type'      => $log_type,
        'name'      => $this->t('@protocol: @event', [
          '@protocol' => $protocol['label'],
          '@event'    => $event['label'],
        ]),
        'timestamp' => $event_ts,
        'status'    => $status,
        'asset'     => $animals,
        'group'     => [$group_asset],
        'notes'     => $notes,
      ];

      if ($log_type === 'breeding') {
        $log_values['breeding_method']    = $method;
        $log_values['is_group_assignment'] = count($animals) > 1;
        if ($sire_id) {
          $log_values['breeding_sire'] = $sire_id;
        }
        if ($lot_id) {
          $log_values['breeding_lot_id'] = $lot_id;
        }
        $breeding_log = $this->createLog($log_values);
      }
      elseif ($log_type === 'pregnancy_check') {
        if ($breeding_log) {
          $log_values['pregnancy_breeding_log'] = $breeding_log;
        }
        $this->createLog($log_values);
      }
      else {
        $this->createLog($log_values);
      }
    }
  }

  /**
   * Returns loaded female animal assets from the form state.
   *
   * @return \Drupal\asset\Entity\AssetInterface[]
   */
  protected function resolveAnimals(FormStateInterface $form_state): array {
    if ($form_state->getValue('animal_source') === 'location') {
      $location_id = $form_state->getValue('location');
      if (empty($location_id)) {
        return [];
      }
      $location = $this->entityTypeManager->getStorage('asset')->load($location_id);
      if (!$location) {
        return [];
      }
      return array_values(array_filter(
        $this->assetLocation->getAssetsByLocation([$location]),
        fn($a) => $a->bundle() === 'animal' && $a->hasField('sex') && $a->get('sex')->value === 'F'
      ));
    }

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
    return $animals;
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
      if ($asset->bundle() === 'animal' && $asset->hasField('sex') && $asset->get('sex')->value === 'F') {
        $count++;
      }
    }
    return $count;
  }

}
