<?php

declare(strict_types=1);

namespace Drupal\farm_breeding\Plugin\views\field;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders the protocol run group header: run name + "Delete run" link.
 *
 * Used as the Views table grouping field so the delete link appears inline
 * in each run's group header rather than in a separate column per row.
 */
#[ViewsField("breeding_protocol_run_group_header")]
class ProtocolRunGroupHeader extends FieldPluginBase {

  public function usesGroupBy(): bool {
    return FALSE;
  }

  public function query(): void {
    $this->ensureMyTable();
    $this->field_alias = $this->query->addField($this->tableAlias, 'group_target_id');
  }

  public function render(ResultRow $values): string|\Stringable {
    $group_id = (int) $this->getValue($values);
    if ($group_id === 0) {
      return '';
    }

    $asset = \Drupal::entityTypeManager()->getStorage('asset')->load($group_id);
    $name  = $asset ? Html::escape($asset->label()) : $this->t('Run #@id', ['@id' => $group_id]);

    $delete_link = \Drupal::linkGenerator()->generate(
      $this->t('Delete run'),
      Url::fromRoute('farm_breeding.protocol_run_delete', ['group_id' => $group_id]),
    );

    return Markup::create('<span class="protocol-run-label">' . $name . '</span> ' . $delete_link);
  }

}
