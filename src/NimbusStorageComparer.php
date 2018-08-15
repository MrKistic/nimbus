<?php

namespace Drupal\nimbus;

use Drupal\Core\Config\StorageComparer as CoreStorageComparer;

/**
 * Overridden StorageComparer so we can control certain aspects of configuration
 * comparison.
 */
class NimbusStorageComparer extends CoreStorageComparer {

  /**
   * Remove the given filename from the changelist
   */
  public function ignoreFile($collection, $op, $name) {
    $this->removeFromChangelist($collection, $op, $name);
  }

}
