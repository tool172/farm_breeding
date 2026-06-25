<?php

declare(strict_types=1);

namespace Drupal\farm_breeding\Plugin\views\area;

use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;

/**
 * Renders a "Delete protocol run" button in the protocol run detail page header.
 *
 * Reads the group_id from the first URL argument set by the Views argument
 * handler (the group_target_id argument on page_2).
 */
#[ViewsArea("breeding_protocol_run_delete_area")]
class ProtocolRunDeleteArea extends AreaPluginBase {

  public function render($empty = FALSE): array {
    if ($empty && empty($this->options['empty'])) {
      return [];
    }

    $group_id = (int) ($this->view->args[0] ?? 0);
    if ($group_id === 0) {
      return [];
    }

    return [
      '#type'       => 'link',
      '#title'      => $this->t('Delete protocol run'),
      '#url'        => Url::fromRoute('farm_breeding.protocol_run_delete', ['group_id' => $group_id]),
      '#attributes' => ['class' => ['button', 'button--danger', 'button--small']],
    ];
  }

}
