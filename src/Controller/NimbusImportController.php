<?php

namespace Drupal\nimbus\Controller;

use Drupal\Core\Config\ConfigException;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\nimbus\NimbusStorageComparer;
use Drupal\nimbus\config\ProxyFileStorage;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Class NimbusImportController.
 *
 * @package Drupal\nimbus\Controller
 */
class NimbusImportController {

  /**
   * The config target.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  private $configTarget;
  /**
   * The config manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  private $configManager;

  /**
   * The config active.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  private $configActive;

  /**
   * NimbusExportController constructor.
   *
   * @param \Drupal\Core\Config\StorageInterface $config_target
   *   The target config storage.
   * @param \Drupal\Core\Config\ConfigManagerInterface $config_manager
   *   The config manager.
   * @param \Drupal\Core\Config\StorageInterface $config_active
   *   The active config storage.
   */
  public function __construct(StorageInterface $config_target, ConfigManagerInterface $config_manager, StorageInterface $config_active) {
    $this->configTarget = $config_target;
    $this->configManager = $config_manager;
    $this->configActive = $config_active;
  }

  /**
   * The configuration import.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   Input object.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   Output object.
   *
   * @return bool
   *   Return false if something went wrong otherwise no return value.
   */
  public function configurationImport(InputInterface $input, OutputInterface $output) {
    $output->writeln('Overriden Import');

    $active_storage = \Drupal::service('config.storage');
    /** @var \Drupal\nimbus\config\ProxyFileStorage $source_storage */
    $source_storage = \Drupal::service('config.storage.staging');

    /** @var \Drupal\Core\Config\ConfigManagerInterface $config_manager */
    $config_manager = \Drupal::service('config.manager');
    $storage_comparer = new NimbusStorageComparer($source_storage, $active_storage, $config_manager);

    if (!$storage_comparer->createChangelist()->hasChanges()) {
      $output->writeln('There are no changes to import.');
      return TRUE;
    }

    // Remove any ignore.* files as required
    $ignore = [];
    foreach ($storage_comparer->getAllCollectionNames() as $collection) {
      $list = $storage_comparer->getChangelist(NULL, $collection);
      foreach ($list as $key => $names) {
        foreach ($names as $name) {
          if (strpos($name, 'ignore.') === 0) {
            $delete = substr($name, strlen('ignore.'));
            $storage_comparer->ignoreFile($collection, $key, $name);
            $storage_comparer->ignoreFile($collection, $key, $delete);
            $ignore[$name] = $delete;
          }
        }
      }
    }

    // Create the change list
    $change_list = [];
    foreach ($storage_comparer->getAllCollectionNames() as $collection) {
      $list = $storage_comparer->getChangelist(NULL, $collection);
      $change_list[$collection] = $list;
    }

    // Make sure we have any changes left to process
    $empty = TRUE;
    foreach ($change_list as $key=>$item) {
      // changelist
      foreach ($item as $arr) {
        // op
        $empty = $empty && empty($arr);
      }
    }
    if ($empty) {
      $output->writeln('There are no changes to import.');
      return TRUE;
    }

    // Ask the question to process
    $this->createTable($change_list, $output);
    $helper = new QuestionHelper();
    $question = new ConfirmationQuestion("Import the listed configuration changes? \n(y/n) ", !$input->isInteractive());

    if ($helper->ask($input, $output, $question)) {

      // Now because the bloody import process ignores the storage_comparitor and
      // validates *all* filenames again, we have to physically move the ignored
      // files :(
      $i = 0;
      $moved = [];
      $tmpDir = file_directory_temp();
      $movedDir = "$tmpDir/nimbus";
      if (!is_dir($movedDir)) {
        mkdir($movedDir);
      }
      foreach ($ignore as $name => $delete) {

        // get the file path
        $file_path = $source_storage->getFilePath($name);
        $dirs = explode("\n", $file_path);
        foreach ($dirs as $dir) {

          // store the path, name and temp file
          $i++;
          $movedFrom = "$dir/$name.yml";
          $movedFile = "$movedDir/$i-$name.yml";
          $moved[] = [
            'from' => $movedFrom,
            'moved' => $movedFile
          ];

          // move to temp file
          // print "Moving file: $movedFrom \n";
          // print "Moving to: $movedFile \n";
          @rename($movedFrom, $movedFile);

        }

        // get the file path
        $file_path = $source_storage->getFilePath($delete);
        $dirs = explode("\n", $file_path);
        foreach ($dirs as $dir) {

          // store the path, name and temp file
          $i++;
          $movedFrom = "$dir/$delete.yml";
          $movedFile = "$movedDir/$i-$delete.yml";
          $moved[] = [
            'from' => $movedFrom,
            'moved' => $movedFile
          ];

          // move to temp file
          // print "Moving file: $movedFrom \n";
          // print "Moving to: $movedFile \n";
          @rename($movedFrom, $movedFile);

        }

      }

      $config_importer = new ConfigImporter(
        $storage_comparer,
        \Drupal::service('event_dispatcher'),
        \Drupal::service('config.manager'),
        \Drupal::lock(),
        \Drupal::service('config.typed'),
        \Drupal::moduleHandler(),
        \Drupal::service('module_installer'),
        \Drupal::service('theme_handler'),
        \Drupal::service('string_translation')
      );

      if ($config_importer->alreadyImporting()) {
        $output->writeln('Another request may be synchronizing configuration already.');
        foreach ($moved as $item) {
          rename($item['moved'], $item['from']);
        }
        return FALSE;
      }
      try {
        $config_importer->import();
        $output->writeln('The configuration was imported successfully.');
        foreach ($moved as $item) {
          rename($item['moved'], $item['from']);
        }
      }
      catch (ConfigException $e) {
        $message = 'The import failed due for the following reasons:' . "\n";
        $message .= implode("\n", $config_importer->getErrors());
        watchdog_exception('config_import', $e);
        $output->writeln($message);
        foreach ($moved as $item) {
          rename($item['moved'], $item['from']);
        }
        return FALSE;
      }
    }
    else {
      $output->writeln('Aborted !');
      return FALSE;
    }
  }

  /**
   * Create a beautiful table.
   *
   * @param mixed $rows
   *   Rows array from the diff.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The symfony console output object.
   */
  protected function createTable($rows, OutputInterface $output) {
    $file_storage = \Drupal::service('config.storage.staging');
    if ($file_storage instanceof ProxyFileStorage) {
      $table = new Table($output);
      $elements = [];
      foreach ($rows as $collection => $row) {
        foreach ($row as $key => $config_names) {
          foreach ($config_names as $config_name) {
            $elements[] = [
              $collection,
              $config_name,
              $key,
              $file_storage->getFilePath($config_name),
            ];
          }
        }
      }
      $table
        ->setHeaders(['Collection', 'Config', 'Operation', 'Directory'])
        ->setRows($elements);
      $table->render();
    }
  }

}
