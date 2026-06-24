<?php

declare(strict_types=1);

namespace Drupal\farm_breeding\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for farm_breeding reproductive protocols.
 */
class BreedingSettingsForm extends ConfigFormBase {

  const SETTINGS = 'farm_breeding.protocols';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'farm_breeding_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [static::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $protocols = $this->config(static::SETTINGS)->get('protocols') ?? [];

    $form['protocols'] = [
      '#tree' => TRUE,
    ];

    foreach ($protocols as $id => $protocol) {
      $method     = $protocol['method'] ?? 'ai';
      $method_key = ($method === 'et') ? 'et' : 'ai';
      $method_label = ($method === 'et') ? 'ET' : 'AI';

      $form['protocols'][$id] = [
        '#type'        => 'fieldset',
        '#title'       => $protocol['label'],
        '#description' => $this->t('Species: @species', ['@species' => implode(', ', $protocol['species'] ?? [])]),
      ];

      $form['protocols'][$id]['enabled'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Enabled'),
        '#default_value' => (bool) ($protocol['enabled'] ?? FALSE),
      ];

      if (isset($protocol['events'][$method_key])) {
        $form['protocols'][$id]['method_hour'] = [
          '#type'          => 'number',
          '#title'         => $this->t('@method timing (hours from Day 0)', ['@method' => $method_label]),
          '#description'   => $this->t('Hour offset from the start of Day 0 at which the @method event is scheduled.', ['@method' => $method_label]),
          '#default_value' => (int) $protocol['events'][$method_key]['hour'],
          '#min'           => 0,
          '#step'          => 1,
        ];
      }

      if (isset($protocol['events']['preg_check'])) {
        $form['protocols'][$id]['preg_check_day'] = [
          '#type'          => 'number',
          '#title'         => $this->t('Pregnancy check (days from Day 0)'),
          '#description'   => $this->t('Day on which the pending pregnancy check log is scheduled.'),
          '#default_value' => (int) $protocol['events']['preg_check']['day'],
          '#min'           => 1,
          '#step'          => 1,
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config    = $this->configFactory->getEditable(static::SETTINGS);
    $protocols = $config->get('protocols') ?? [];

    foreach ($form_state->getValue('protocols') as $id => $values) {
      if (!isset($protocols[$id])) {
        continue;
      }

      $protocols[$id]['enabled'] = (bool) $values['enabled'];

      $method_key = ($protocols[$id]['method'] === 'et') ? 'et' : 'ai';
      if (isset($protocols[$id]['events'][$method_key], $values['method_hour'])) {
        $protocols[$id]['events'][$method_key]['hour'] = (int) $values['method_hour'];
      }

      if (isset($protocols[$id]['events']['preg_check'], $values['preg_check_day'])) {
        $protocols[$id]['events']['preg_check']['day'] = (int) $values['preg_check_day'];
      }
    }

    $config->set('protocols', $protocols)->save();

    $this->messenger()->addStatus($this->t('Breeding settings saved.'));
  }

}
