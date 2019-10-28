<?php


class S3Migration_Command
{

  private function doprechecks(){
    if (!class_exists('WP_CLI')) {
      echo "ERROR: class 'WP_CLI' not found";
      exit(2);
    }
    
    if (php_sapi_name() != 'cli') {
      WP_CLI::error("This script must run from CLI");
      WP_CLI::hast(2);
    }

    if (!class_exists('Amazon_S3_And_CloudFront')) {
      WP_CLI::error("WP Offload Media Lite plugin is not active!");
      WP_CLI::halt(1);
    }

    // print_r($GLOBALS);
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
   * [--protocol=<protocol>]
   * default: https
   * ---
   * options:
   *   - https
   *   - http
   * ---
   * 
   * ## EXAMPLES
   * 
   *    wp aws-s3-migrate
   * 
   *    wp aws-s3-migrate --output
   * 
   *    wp aws-s3-migrate --protocl=http 
   * 
   *    wp aws-s3-migrate --purge
   * 
   * @when after_wp_load
   */
  public function __invoke($args, $assoc_args)
  {
    global $as3cf;

    $this->doprechecks();

    WP_CLI::debug($as3cf);

    WP_CLI::log("Starting migration to S3");

    //get input args
    $protocol = WP_CLI\Utils\get_flag_value($assoc_args, 'protocol', 'https');
    $output = WP_CLI\Utils\get_flag_value($assoc_args, 'output', false);
    $purge = WP_CLI\Utils\get_flag_value($assoc_args, 'purge', false);

    WP_CLI::debug("Inputs: Protocol=" . json_encode($protocol) . " / Output=" . json_encode($output) . " / Purge=" . json_encode($purge));

    if ($purge === true) {

      $this->purge($output);

      WP_CLI::halt(0);
      return;
    }

    $protocol = $protocol . "://";
    WP_CLI::debug("Protocol is: " . $protocol);


    WP_CLI::do_hook('init');

    WP_CLI::debug("as3cf-class: " . $as3cf);


    $siteIDs = $this->getAllSiteIDs();

    foreach ($siteIDs as $id) {
      $this->runMigration($id, $protocol, $as3cf);
    }
  }

  private function buildAndValidateS3($as3cf)
  {
    $s3 = new stdClass();
    $s3->region = $this->getS3Region($as3cf);
    $s3->bucket = $this->getS3Bucket($as3cf);

    if (!$s3->bucket || !$s3->region) {
      WP_CLI::error("WP Offload S3 Lite setup  appears to be incomplete.");
      WP_CLI::halt(1);
    }

    return $s3;
  }

  private function getS3Bucket($as3cf)
  {
    return $as3cf->get_setting('bucket');
  }

  private function getS3Region($as3cf)
  {
    return ($as3cf->get_setting('region')) ? $as3cf->get_setting('region') : $as3cf->get_bucket_region($as3cf->get_setting('bucket'));
  }

  private function getAWSURL($as3cf)
  {
    $s3 = $this->buildAndValidateS3($as3cf);

    if ($as3cf->get_setting('domain') === "cloudfront" && $as3cf->get_setting('cloudfront')) {
      $aws_url = $as3cf->get_setting('cloudfront');
    } else {
      $aws_url = 's3-' . $s3->region . '.amazonaws.com/' . $as3cf->get_setting('bucket');
    }
    return $aws_url;
  }

  private function runMigration(int $siteID, string $protocol, $as3cf)
  {
    WP_CLI::log("Starting migration for site ID " . $siteID);
    $this->switchSiteContext($siteID);

    $this->performUpdateMetadata($as3cf);

    $this->performRewritePostContent($protocol, $as3cf);

    //finally reset context
    $this->resetContext();
  }

  /**
   * Remove all entries from 'postmeta' table with meta_key 'amazonS3_info'
   * 
   * @subcommand purge
   */
  private function purge(bool $output)
  {
    WP_CLI::success("Purging postmeta table...");

    //@todo handle multisite

    //@todo add logging

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

  private function performUpdateMetadata($as3cf)
  {
    $s3 = $this->buildAndValidateS3($as3cf);
    //@TODO make function repeatable -> upsert not always insert
    $media_to_update = $wpdb->get_results("SELECT * FROM " . $wpdb->postmeta . " WHERE meta_key = '_wp_attached_file'");
    // loop through each media item, adding the amazonS3_info meta data
    foreach ($media_to_update as $media_item) {
      $media_meta_data = serialize(
        array(
          'bucket' => $s3->bucket,
          'key'    => $this->getAWSFolderPrefix(as3cf) . $media_item->meta_value,
          'region' => $s3->region,
        )
      );
      // Upsert the postmeta record that WP Offload S3 Lite uses
      update_metadata($wpdb->postmeta, $media_item->post_id, 'amazonS3_info', $media_meta_data);

      // $wpdb->insert(
      //   $wpdb->postmeta,
      //   array(
      //     'post_id'    => $media_item->post_id,
      //     'meta_key'   => 'amazonS3_info',
      //     'meta_value' => $media_meta_data,
      //   )
      // );
    }
  }

  private function performRewritePostContent(string $protocol, $as3cf)
  {
    $wp_folder_prefix = $this->getWPFolderPrefix();
    WP_CLI::debug("WP Folder Prefix is: " . $wp_folder_prefix);

    $aws_url = $this->getAWSURL($as3cf);
    $aws_folder_prefix = $this->getAWSFolderPrefix($as3cf);

    if ($db_connection = mysqli_connect($wpdb->dbhost, $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname)) {
      // Query to update post content 'href'
      $query_post_content_href = updatePostContent(
        'href',
        $wpdb->posts,
        get_site_url($wpdb->blogid) . '/' . $wp_folder_prefix,
        "$protocol$aws_url/$aws_folder_prefix"
      );
      // Query to update post content 'src'
      $query_post_content_src = updatePostContent(
        'src',
        $wpdb->posts,
        get_site_url($wpdb->blogid) . '/' . $wp_folder_prefix,
        "$protocol$aws_url/$aws_folder_prefix"
      );

      $db_connection->query($query_post_content_href);
      $db_connection->query($query_post_content_src);
    } else {
      WP_CLI::error("DB Connection failed!");
      WP_CLI::halt(1);
    }
  }

  private function getWPFolderPrefix()
  {
    //@TODO test if WP_CLI\Utils\basename works as well
    return str_replace(ABSPATH, '', wp_upload_dir()['basedir']) . '/';
  }

  private function getAWSFolderPrefix($as3cf)
  {
    return $as3cf->get_setting('enable-object-prefix') ? $as3cf->get_setting('object-prefix') : $this->getWPFolderPrefix();
  }

  private function getAllSiteIDs()
  {
    $blog_IDs = array(get_current_blog_id());

    // multisite check
    $isMultisite = is_multisite();
    $multiSite_Txt = ($isMultisite === true) ? "YES" : "NO";
    WP_CLI::success("INFO: Is Multisite: " . $multiSite_Txt);


    if ($isMultisite === true) {

      if (function_exists('get_sites') && function_exists('get_current_network_id')) {
        $site_ids = get_sites(array('fields' => 'ids', 'network_id' => get_current_network_id()));
      } else {
        $site_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs WHERE site_id = $wpdb->siteid;");
      }

      $blog_IDs = array_merge($blog_IDs, $site_ids);
    }

    WP_CLI::debug("Found " . count($blog_IDs) . " site ids");

    return $blog_IDs;
  }

  private function switchSiteContext(int $blogID)
  {
    if (is_multisite() === true) {
      switch_to_blog($blogID);
      WP_CLI::success("Swtiched site id to " . $blogID);
    } else {
      WP_CLI::log("No multisite -> no switch necessary.");
    }
  }

  private function resetContext()
  {
    WP_CLI::debug("restoring blog...");
    restore_current_blog();
  }

  private function updatePostContent($type, $table, $local_uri, $aws_uri, $revert = false)
  {
    $from = (!$revert) ? $local_uri : $aws_uri;
    $to   = (!$revert) ? $aws_uri : $local_uri;
    return "UPDATE $table SET post_content = replace(post_content, '$type=\"$from', '$type=\"$to');";
  }
}

WP_CLI::add_command('aws-s3-migrate', 'S3Migration_Command');
