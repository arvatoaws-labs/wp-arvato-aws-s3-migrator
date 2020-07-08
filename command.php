<?php

use DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item as Media_Library_Item;
// use WP_CLI;

class S3Migration_Command
{

  /** @var string $PLUGIN_MIN_VERSION */
  private static $PLUGIN_MIN_VERSION = "2.3";

  private static $IS_DEBUG_MODE = false;

  private static $OUTPUT = false;

  /**
   * @return void
   */
  private function doPrechecks()
  {
    //Check if WP CLI is started from command line
    if (php_sapi_name() != 'cli') {
      WP_CLI::error("This script must run from CLI");
      WP_CLI::halt(2);
    }

    //Is AS3CF Plugin active?
    $options = array(
      'return'     => true,   // Return 'STDOUT'; use 'all' for full object.
      'parse'      => 'json', // Parse captured STDOUT to JSON array.
      'launch'     => false,  // Reuse the current process.
      'exit_error' => true,   // Halt script execution on error.
    );
    $plugin = WP_CLI::runcommand('plugin get amazon-s3-and-cloudfront --format=json', $options);

    if ($plugin['status'] !== 'active') {
      WP_CLI::error("WP Offload Media Lite plugin is not active!");
      WP_CLI::error($plugin);
      WP_CLI::halt(1);
    }

    //Is AS3CF installed in a valid version?
    $pluginVersion = $GLOBALS['aws_meta']['amazon-s3-and-cloudfront']['version'];
    WP_CLI::log("Installed AS3CF-Plugin Version: " . $pluginVersion);

    if ($pluginVersion < self::$PLUGIN_MIN_VERSION) {
      WP_CLI::error("AS3CF-Plugin version has to be at least " . self::$PLUGIN_MIN_VERSION . "!");
      WP_CLI::halt(1);
    }
  }



  /**
   * Starts S3 migration
   * 
   * ## OPTIONS
   * 
   * [--output]
   * : Display a detailed log of all migrated files
   * 
   * [--purge]
   * : Purges the postmeta table for all entries with 'amazonS3_info'
   * 
   * 
   * ## EXAMPLES
   * 
   *    wp aws-s3-migrate
   * 
   *    wp aws-s3-migrate --output
   * 
   *    wp aws-s3-migrate --purge
   * 
   * @when after_wp_load
   */
  public function __invoke($args, $assoc_args)
  {

    $this->doPrechecks();

    WP_CLI::warning('Starting WP MediaLibrary migration');

    //get input args
    // $protocol = WP_CLI\Utils\get_flag_value($assoc_args, 'protocol', 'https');
    self::$OUTPUT = WP_CLI\Utils\get_flag_value($assoc_args, 'output', false);
    $purge = WP_CLI\Utils\get_flag_value($assoc_args, 'purge', false);

    self::$IS_DEBUG_MODE = WP_CLI::get_config('debug');
 

    if ($purge === true) {

      $this->purge();
      // WP_CLI::halt(0); //stop processing with exit code 0 = success
      // return;
    }

    $this->runMigration();


    WP_CLI::success("Migration done!");
  }

 

  /**
   * 
   */
  private function migrateItem(string $provider, string $region, string $bucket, string $path, bool $is_private, int $source_id, string $source_path, string $original_filename = null, array $private_sizes = array(), $id = null)
  {

    $migratedItem = new Media_Library_Item(
      $provider,
      $region,
      $bucket,
      $path,
      $is_private,
      $source_id,
      $source_path,
      $original_filename,
      $private_sizes
    );

    $result = $migratedItem->save();

    if (is_wp_error($result)) {
      WP_CLI::error('FAIL');
      WP_CLI::error($result->get_error_message());
      return false;
    } else {
      WP_CLI::debug('saved', "PostId-" . $source_id);
      WP_CLI::debug("Item saved to new Id " . $result, "PostId-" . $source_id);
    }

    return $result;
  }

  /**
   * 
   * 
   * @return void
   * 
   */
  private function runMigration()
  {
    /** @var Amazon_S3_And_CloudFront $as3cf */
    global $as3cf;


    $count = Media_Library_Item::count_attachments(true, true);
    WP_CLI::log("Not offloaded attachments: " . $count['not_offloaded']);

    $notOffloadedIds = Media_Library_Item::get_missing_source_ids(null, $count['not_offloaded']);

    WP_CLI::debug("IDs of not offloaded Attachments:");
    $progress = WP_CLI\Utils\make_progress_bar('Migrating attachments', count($notOffloadedIds));

    $items = array();
    foreach ($notOffloadedIds as $index => $postId) {
      WP_CLI::debug("PostID: " .  $postId, "PostId-" . $postId);

      //check if there are already amazon_s3_info meta_data
      $old_item = Media_Library_Item::get_by_source_id($postId);

      if ($old_item === false) {
        WP_CLI::debug("Item has no legacy entries in post_meta", "PostId-" . $postId);

        $attachment = wp_get_attachment_metadata($postId);

        $settings = $as3cf->get_settings();

        $result = $this->migrateItem(
          $settings['provider'],
          $settings['region'],
          $settings['bucket'],
          path_join($settings['object-prefix'], $attachment['file']), 
          false, 
          $postId,
          $attachment['file'], 
          $attachment['file'] 

        );

      } else {

        WP_CLI::debug("Found legacy infos in post_meta", "PostId-" . $postId);

        $result = $this->migrateItem(
          $old_item->provider(),
          $old_item->region(),
          $old_item->bucket(),
          $old_item->path(),
          $old_item->is_private(),
          $postId,
          $old_item->source_path(),
          wp_basename($old_item->original_source_path()),
          $old_item->private_sizes()
        );
      }

      array_push($items, array('PostId' => $postId, 'AS3CF' => $result));

      $progress->tick();
    }

    $progress->finish();

    //print additional output to console
    if(self::$OUTPUT === true) {
      $this->printResultTable($items);
    }
  }


  /**
   * Prints a final table with all migratetd items
   * 
   *  * ```
   * Example output
   *
   * # +--------+-------+
   * # | PostId | AS3CF |
   * # +--------+-------+
   * # |   23   |  12   |
   * # +--------+-------+
   * # |   49   | false |
   * # +--------+-------+
   * ```
   * 
   * @param array $items
   * @param array $header
   * 
   * @return void
   */
  private function printResultTable(array $items, array $header = array( 'PostId', 'AS3CF'))
  {
    WP_CLI\Utils\format_items('table', $items, $header);
  }

  /**
   * Remove all entries from 'postmeta' table with meta_key 'amazonS3_info'
   *  
   * @param bool $output
   * 
   * @return void
   */
  private function purge(bool $output)
  {
    WP_CLI::warning("Purging postmeta table");

    WP_CLI::warning("Purging postmeta table");
    WP_CLI::warning("Purging postmeta table");
    WP_CLI::warning("Purging postmeta table");

    //@todo add detailed logging

    /**
     * @global wpdb $wpdb
     */
    global $wpdb;

    $wpdb->delete(
      $wpdb->postmeta,
      array(
        'meta_key' => 'amazonS3_info',
      )
    );

    WP_CLI::success("Purging done!");
  }

}

WP_CLI::add_command('aws-s3-migrate', 'S3Migration_Command');
