<?php

declare(strict_types=1);

namespace Drupal\farm_breeding\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a direct "Edit" link to the log edit page.
 */
#[ViewsField("breeding_log_edit_link")]
class LogEditLink extends FieldPluginBase {

  public function usesGroupBy(): bool {
    return FALSE;
  }

  public function query(): void {
    $this->ensureMyTable();
    $this->field_alias = $this->query->addField($this->tableAlias, 'id');
  }

  public function render(ResultRow $values): string|\Stringable {
    $log_id = (int) $this->getValue($values);
    if ($log_id === 0) {
      return '';
    }
    return \Drupal::linkGenerator()->generate(
      $this->t('Edit'),
      Url::fromUserInput('/log/' . $log_id . '/edit', [
        'query' => \Drupal::destination()->getAsArray(),
      ]),
    );
  }

}
