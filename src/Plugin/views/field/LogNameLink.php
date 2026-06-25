<?php

declare(strict_types=1);

namespace Drupal\farm_breeding\Plugin\views\field;

use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a log name as a link to its canonical page.
 *
 * Views field token substitution ([field] / {{ field }}) requires the source
 * field to be non-excluded. This plugin avoids tokens entirely by selecting
 * both id and name from log_field_data directly.
 */
#[ViewsField("breeding_log_name_link")]
class LogNameLink extends FieldPluginBase {

  private string $name_alias;

  public function usesGroupBy(): bool {
    return FALSE;
  }

  public function query(): void {
    $this->ensureMyTable();
    $this->field_alias = $this->query->addField($this->tableAlias, 'id');
    $this->name_alias = $this->query->addField($this->tableAlias, 'name');
  }

  public function render(ResultRow $values): string|\Stringable {
    $log_id = (int) $this->getValue($values);
    if ($log_id === 0) {
      return '';
    }
    $name = $values->{$this->name_alias} ?? '';
    return \Drupal::linkGenerator()->generate(
      $name ?: (string) $this->t('Log #@id', ['@id' => $log_id]),
      Url::fromUserInput('/log/' . $log_id),
    );
  }

}
