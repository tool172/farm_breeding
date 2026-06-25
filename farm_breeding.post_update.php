<?php

/**
 * @file
 * Post-update hooks for farm_breeding.
 *
 * Run via `drush updb` for sites upgrading from an earlier version.
 * Fresh installs do not need these — hook_install() handles everything.
 */

declare(strict_types=1);

/**
 * Install Breeding and Pregnancy Check log bundle fields (upgrade path).
 *
 * installBundles() takes the EntityTypeInterface for 'log', not a bundle
 * config entity. Installs all bundle plugin fields for farm_breeding at once.
 */
function farm_breeding_post_update_install_breeding_fields(&$sandbox): void {
  $entity_type = \Drupal::entityTypeManager()->getDefinition('log');
  \Drupal::service('entity.bundle_plugin_installer')
    ->installBundles($entity_type, ['farm_breeding']);
}

/**
 * Kept for upgrade path compatibility — breeding and pregnancy_check fields
 * are both installed by the hook above in a single installBundles() call.
 */
function farm_breeding_post_update_install_pregnancy_check_fields(&$sandbox): void {
  // No-op: covered by farm_breeding_post_update_install_breeding_fields().
}

/**
 * Drop orphaned field_deleted_* tables left by prior failed uninstall attempts.
 *
 * Each time drush pm:uninstall farm_breeding crashed (because the
 * pregnancy_check tables were missing), onFieldDefinitionDelete() had already
 * renamed some breeding field tables to field_deleted_{data,revision}_{hash}
 * before the exception was thrown.  Those tables are stuck because Drupal's
 * field purge job never ran.  They block a fresh uninstall: the rename step
 * would try to write the same hash name again and fail with "table already
 * exists".  Since we are about to remove the module (and its data), it is safe
 * to drop these orphaned tables outright.
 */
function farm_breeding_post_update_drop_orphaned_field_deleted_tables(&$sandbox): void {
  $schema = \Drupal::database()->schema();
  foreach ($schema->findTables('field_deleted%') as $table) {
    $schema->dropTable($table);
  }
}

/**
 * Create pregnancy_check field tables that were missed during install.
 *
 * The original installBundles() call registered pregnancy_check field
 * definitions in the key-value store but did not create their database tables.
 * Without these tables, drush pm:uninstall farm_breeding crashes because
 * entity_module_preuninstall() → uninstallBundles() calls
 * onFieldDefinitionDelete() which issues an UPDATE against a non-existent
 * table (fatal on PostgreSQL via ::regclass cast).
 *
 * We call onFieldStorageDefinitionCreate() on the storage handler directly
 * rather than through FieldStorageDefinitionListener, so we create only the
 * DB tables without double-registering definitions that are already tracked.
 */
function farm_breeding_post_update_create_pregnancy_check_tables(&$sandbox): void {
  $log_storage = \Drupal::entityTypeManager()->getStorage('log');
  $db_schema = \Drupal::database()->schema();
  $table_mapping = $log_storage->getTableMapping();
  $bundle_handler = \Drupal::entityTypeManager()->getHandler('log', 'bundle_plugin');

  foreach ($bundle_handler->getFieldDefinitions('pregnancy_check') as $def) {
    $field_storage_def = $def->getFieldStorageDefinition();

    if (!$table_mapping->requiresDedicatedTableStorage($field_storage_def)) {
      continue;
    }

    $table = $table_mapping->getDedicatedDataTableName($field_storage_def);
    if ($db_schema->tableExists($table)) {
      continue;
    }

    $log_storage->onFieldStorageDefinitionCreate($field_storage_def);
  }
}

/**
 * Import protocol runs View from config/install/ on already-installed sites.
 */
function farm_breeding_post_update_import_protocol_runs_view(&$sandbox): void {
  \Drupal::service('config.installer')->installDefaultConfig('module', 'farm_breeding');
}

/**
 * Fix View plan token: use asset_field_data.id via relationship instead of log__group.group_target_id.
 */
function farm_breeding_post_update_fix_view_plan_token(&$sandbox): void {
  $view = \Drupal::entityTypeManager()->getStorage('view')->load('farm_breeding_protocol_runs');
  if ($view) {
    $view->delete();
  }
  \Drupal::service('config.installer')->installDefaultConfig('module', 'farm_breeding');
}

/**
 * Replace protocol runs View with updated version (detail display + View plan link).
 */
function farm_breeding_post_update_add_protocol_detail_view(&$sandbox): void {
  $view = \Drupal::entityTypeManager()->getStorage('view')->load('farm_breeding_protocol_runs');
  if ($view) {
    $view->delete();
  }
  \Drupal::service('config.installer')->installDefaultConfig('module', 'farm_breeding');
}

/**
 * Replace protocol runs View: use custom ProtocolRunLink field instead of broken token.
 */
function farm_breeding_post_update_use_protocol_run_link_field(&$sandbox): void {
  $view = \Drupal::entityTypeManager()->getStorage('view')->load('farm_breeding_protocol_runs');
  if ($view) {
    $view->delete();
  }
  \Drupal::service('config.installer')->installDefaultConfig('module', 'farm_breeding');
}

/**
 * Replace protocol runs View: use LogNameLink plugin for event name links.
 *
 * Drupal 11 Views field token substitution ([id] / {{ id }}) requires the
 * source field to not be excluded. Both plugins now select column values
 * directly, bypassing the token mechanism entirely.
 */
function farm_breeding_post_update_use_log_name_link_field(&$sandbox): void {
  $view = \Drupal::entityTypeManager()->getStorage('view')->load('farm_breeding_protocol_runs');
  if ($view) {
    $view->delete();
  }
  \Drupal::service('config.installer')->installDefaultConfig('module', 'farm_breeding');
}

/**
 * Add entity operations column to protocol runs listing and detail views.
 */
function farm_breeding_post_update_add_operations_column(&$sandbox): void {
  $view = \Drupal::entityTypeManager()->getStorage('view')->load('farm_breeding_protocol_runs');
  if ($view) {
    $view->delete();
  }
  \Drupal::service('config.installer')->installDefaultConfig('module', 'farm_breeding');
}

/**
 * Replace operations dropdown with a direct Edit link on protocol run views.
 */
function farm_breeding_post_update_replace_operations_with_edit_link(&$sandbox): void {
  $view = \Drupal::entityTypeManager()->getStorage('view')->load('farm_breeding_protocol_runs');
  if ($view) {
    $view->delete();
  }
  \Drupal::service('config.installer')->installDefaultConfig('module', 'farm_breeding');
}

/**
 * Install animal_stage bundle field on Animal assets (added in v1beta4).
 *
 * animal_stage belongs to the existing 'animal' bundle which farm_breeding
 * does not own, so installBundles() won't touch it. We use the field storage
 * and definition listeners directly.
 */
function farm_breeding_post_update_install_animal_stage_field(&$sandbox): void {
  $options = [
    'type'        => 'list_string',
    'label'       => t('Life stage'),
    'description' => t('Current reproductive/age stage.'),
    'allowed_values' => [
      'juvenile'        => t('Juvenile — calf / kid / lamb / piglet / foal'),
      'immature_female' => t('Immature female — heifer / doeling / gilt / ewe lamb / filly'),
      'mature_female'   => t('Mature female — cow / doe / sow / ewe / mare'),
      'intact_male'     => t('Intact male — bull / buck / boar / ram / stallion'),
      'castrated_male'  => t('Castrated male — steer / wether / barrow / gelding'),
    ],
    'weight' => ['form' => 15, 'view' => 15],
  ];

  $field_definition = \Drupal::service('farm_field.factory')
    ->bundleFieldDefinition($options);

  $storage_definition = $field_definition->getFieldStorageDefinition();
  \Drupal::service('field_storage_definition.listener')
    ->onFieldStorageDefinitionCreate($storage_definition);

  \Drupal::service('field_definition.listener')
    ->onFieldDefinitionCreate($field_definition);
}
