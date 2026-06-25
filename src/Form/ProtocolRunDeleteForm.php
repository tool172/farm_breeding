<?php

declare(strict_types=1);

namespace Drupal\farm_breeding\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirmation form for deleting an entire protocol run.
 *
 * Cascade-deletes all logs in the group (pregnancy_check first so
 * entity_reference_integrity_enforce does not block breeding log deletion),
 * then deletes the Group asset itself.
 */
class ProtocolRunDeleteForm extends ConfirmFormBase {

  protected ?object $groupAsset = NULL;

  protected array $logs = [];

  public function getFormId(): string {
    return 'farm_breeding_protocol_run_delete_form';
  }

  public function getQuestion(): TranslatableMarkup {
    return $this->t('Delete protocol run "@name"?', [
      '@name' => $this->groupAsset?->label() ?? '',
    ]);
  }

  public function getDescription(): TranslatableMarkup {
    return $this->t('This action cannot be undone.');
  }

  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Delete protocol run');
  }

  public function getCancelUrl(): Url {
    return Url::fromUserInput('/farm/breeding/protocols/' . ($this->groupAsset?->id() ?? ''));
  }

  public function buildForm(array $form, FormStateInterface $form_state, string $group_id = NULL): array {
    $asset_storage = \Drupal::entityTypeManager()->getStorage('asset');
    $log_storage   = \Drupal::entityTypeManager()->getStorage('log');

    $this->groupAsset = $asset_storage->load((int) $group_id);
    if (!$this->groupAsset || $this->groupAsset->bundle() !== 'group') {
      throw new NotFoundHttpException();
    }

    $log_ids    = $log_storage->getQuery()
      ->condition('group', (int) $group_id)
      ->sort('timestamp')
      ->accessCheck(FALSE)
      ->execute();
    $this->logs = $log_storage->loadMultiple($log_ids);

    $form = parent::buildForm($form, $form_state);

    if (!empty($this->logs)) {
      $items = [];
      foreach ($this->logs as $log) {
        $items[] = $log->label() ?: $this->t('@type #@id', [
          '@type' => $log->bundle(),
          '@id'   => $log->id(),
        ]);
      }
      $form['log_list'] = [
        '#weight' => -5,
        '#theme'  => 'item_list',
        '#title'  => $this->formatPlural(
          count($items),
          'The following log will also be permanently deleted:',
          'The following @count logs will also be permanently deleted:',
        ),
        '#items'  => $items,
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $log_storage = \Drupal::entityTypeManager()->getStorage('log');

    // Delete pregnancy_check logs first — they hold a reference to breeding
    // logs, which entity_reference_integrity_enforce would otherwise block.
    $pc_logs = array_filter($this->logs, static fn($l) => $l->bundle() === 'pregnancy_check');
    if ($pc_logs) {
      $log_storage->delete($pc_logs);
    }

    $other_logs = array_filter($this->logs, static fn($l) => $l->bundle() !== 'pregnancy_check');
    if ($other_logs) {
      $log_storage->delete($other_logs);
    }

    $label = $this->groupAsset->label();
    $count = count($this->logs);
    $this->groupAsset->delete();

    $this->messenger()->addStatus($this->formatPlural(
      $count,
      'Protocol run "@name" and 1 log have been deleted.',
      'Protocol run "@name" and @count logs have been deleted.',
      ['@name' => $label],
    ));

    $form_state->setRedirectUrl(Url::fromRoute('view.farm_breeding_protocol_runs.page_1'));
  }

}
