<?php

declare(strict_types=1);

namespace Drupal\farm_breeding\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a "Delete run" link to the protocol run delete confirmation page.
 *
 * Directly selects group_target_id from the already-joined log__group table
 * so we can build the delete URL without relying on Views field token substitution.
 */
#[ViewsField("breeding_protocol_run_delete_link")]
class ProtocolRunDeleteLink extends FieldPluginBase {

  public function usesGroupBy(): bool {
    return FALSE;
  }

  public function query(): void {
    $this->ensureMyTable();
    $this->field_alias = $this->query->addField(
      $this->tableAlias,
      'group_target_id',
    );
  }

  public function render(ResultRow $values): string|\Stringable {
    $group_id = (int) $this->getValue($values);
    if ($group_id === 0) {
      return '';
    }
    return \Drupal::linkGenerator()->generate(
      $this->t('Delete run'),
      Url::fromRoute('farm_breeding.protocol_run_delete', ['group_id' => $group_id]),
    );
  }

}
