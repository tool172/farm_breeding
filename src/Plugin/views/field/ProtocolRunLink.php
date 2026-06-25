<?php

declare(strict_types=1);

namespace Drupal\farm_breeding\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a "View plan" link to the breeding protocol run detail page.
 *
 * Directly selects group_target_id from the already-joined log__group table,
 * avoiding the Views field token mechanism which does not substitute values
 * from excluded fields in joined tables.
 */
#[ViewsField("breeding_protocol_run_link")]
class ProtocolRunLink extends FieldPluginBase {

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
      $this->t('View plan'),
      Url::fromUserInput('/breeding/protocols/' . $group_id),
    );
  }

}
