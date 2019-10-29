<?php


class S3Migration_Command
{
  /**
   * @var bool
   */
  private $S3validationDone = false;

  /**
   * 
   */
  private function doPrechecks()
  {

    if (php_sapi_name() != 'cli') {
      WP_CLI::error("This script must run from CLI");
      WP_CLI::hast(2);
    }

    if (!class_exists('Amazon_S3_And_CloudFront')) {
      WP_CLI::error("WP Offload Media Lite plugin is not active!");
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

    $this->doPrechecks();

    //get input args
    $protocol = WP_CLI\Utils\get_flag_value($assoc_args, 'protocol', 'https');
    $output = WP_CLI\Utils\get_flag_value($assoc_args, 'output', false);
    $purge = WP_CLI\Utils\get_flag_value($assoc_args, 'purge', false);

    WP_CLI::debug("Inputs: Protocol=" . json_encode($protocol) . " / Output=" . json_encode($output) . " / Purge=" . json_encode($purge));

    $siteIDs = $this->getAllSiteIDs();

    if ($purge === true) {

      foreach ($siteIDs as $id) {
        $this->purge($id, $output);
      }


      // WP_CLI::halt(0); //stop processing with exit code 0 = success
      // return;
    }

    $protocol = $protocol . "://";
    WP_CLI::debug("Protocol is: " . $protocol);

    //run migration for each blog
    WP_CLI::debug("Starting migration to S3");
    foreach ($siteIDs as $id) {
      $this->runMigration($id, $protocol);
    }

    WP_CLI::success("Migration done!");
  }

  /**
   * 
   * @return object
   */
  private function getS3Object()
  {
    $s3 = new stdClass();
    $s3->region = $this->getS3Region();
    $s3->bucket = $this->getS3Bucket();

    //validate object on first GET
    if (!$this->S3validationDone) {
      $this->validateS3Object($s3);
    }

    return $s3;
  }

  /**
   * 
   * @param object $s3
   * 
   * @return bool true
   */
  private function validateS3Object(object $s3)
  {

    if (!$s3->bucket || !$s3->region) {
      WP_CLI::error("WP Offload S3 Lite setup  appears to be incomplete.");
      WP_CLI::halt(1);
    }

    $this->S3validationDone = true;

    WP_CLI::debug("S3-Region: " .  $s3->region);
    WP_CLI::debug("S3-Bucket: " .  $s3->bucket);

    return true;
  }

  /**
   * 
   * @return string bucket name
   */
  private function getS3Bucket()
  {
    global $as3cf;

    return $as3cf->get_setting('bucket');
  }

  /**
   * 
   * @return string s3-region
   */
  private function getS3Region()
  {
    global $as3cf;

    return ($as3cf->get_setting('region')) ? $as3cf->get_setting('region') : $as3cf->get_bucket_region($as3cf->get_setting('bucket'));
  }

  /**
   * @return string
   */
  private function getAWSURL()
  {
    global $as3cf;

    $s3 = $this->getS3Object();

    if ($as3cf->get_setting('domain') === "cloudfront" && $as3cf->get_setting('cloudfront')) {
      $aws_url = $as3cf->get_setting('cloudfront');
    } else {
      $aws_url = 's3-' . $s3->region . '.amazonaws.com/' . $as3cf->get_setting('bucket');
    }

    WP_CLI::debug("AWS URL: " . $aws_url);

    return $aws_url;
  }

  /**
   * 
   * 
   * @param int $siteID
   * @param string $protocol
   * 
   */
  private function runMigration(int $siteID, string $protocol)
  {
    WP_CLI::log("Starting migration for site ID " . $siteID);
    $this->switchSiteContext($siteID);

    $this->performUpdateMetadata();

    $this->performRewritePostContent($protocol);

    //finally reset context
    $this->resetContext();

    WP_CLI::log("Migration done for site ID " . $siteID);
  }

  /**
   * Remove all entries from 'postmeta' table with meta_key 'amazonS3_info'
   *  
   * @param int $siteID
   * @param bool $output
   */
  private function purge(int $siteID, bool $output)
  {
    WP_CLI::info("Purging postmeta table for Blog-ID: " . $siteID);

    $this->switchSiteContext($siteID);
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

    $this->resetContext();

    WP_CLI::success("Purging done!");
  }

  /**
   * 
   */
  private function performUpdateMetadata()
  {
    global $wpdb;

    $s3 = $this->getS3Object();

    $media_to_update = $wpdb->get_results("SELECT * FROM " . $wpdb->postmeta . " WHERE meta_key = '_wp_attached_file'");

    WP_CLI::debug("Media_to_update Size: " . count($media_to_update));

    // loop through each media item, adding the amazonS3_info metadata
    foreach ($media_to_update as $media_item) {

      $mediaMetaData = array(
        'bucket'   => $s3->bucket,
        'key'      => $this->getAWSFolderPrefix() . $media_item->meta_value,
        'region'   => $s3->region,
        'provider' => 'aws'
      );

      // Upsert the postmeta record that WP Offload S3 Lite uses
      update_post_meta($media_item->post_id, 'amazonS3_info', $mediaMetaData);
    }
  }

  /**
   * 
   * @param string $protocol
   */
  private function performRewritePostContent(string $protocol)
  {
    global $wpdb;

    $wp_folder_prefix = $this->getWPFolderPrefix();
    WP_CLI::debug("WP Folder Prefix is: " . $wp_folder_prefix);

    $aws_url = $this->getAWSURL();
    $aws_folder_prefix = $this->getAWSFolderPrefix();
    WP_CLI::debug("AWS Folder Prefix is: " . $aws_folder_prefix);

    if ($db_connection = mysqli_connect($wpdb->dbhost, $wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname)) {
      // Query to update post content 'href'
      WP_CLI::debug("Site-URL: " . get_site_url($wpdb->blogid));

      $query_post_content_href = $this->updatePostContent(
        'href',
        $wpdb->posts,
        get_site_url($wpdb->blogid) . '/' . $wp_folder_prefix,
        "$protocol$aws_url/$aws_folder_prefix"
      );
      // Query to update post content 'src'
      $query_post_content_src = $this->updatePostContent(
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

  /**
   * 
   */
  private function getWPFolderPrefix()
  {
    return str_replace(ABSPATH, '', wp_upload_dir()['basedir']) . '/';
  }

  /**
   * 
   */
  private function getAWSFolderPrefix()
  {
    global $as3cf;

    return $as3cf->get_setting('enable-object-prefix') ? $as3cf->get_setting('object-prefix') : $this->getWPFolderPrefix();
  }

  /**
   * Get all IDs of the blog(s). 
   * 
   * @return array Array with blog ids
   */
  private function getAllSiteIDs()
  {
    global $wpdb;

    $blog_IDs = array(get_current_blog_id());

    // multisite check
    $isMultisite = is_multisite();
    // $multiSite_Txt = ($isMultisite === true) ? "YES" : "NO";
    WP_CLI::success("Is Multisite: " . json_encode($isMultisite));

    if ($isMultisite === true) {

      if (function_exists('get_sites') && function_exists('get_current_network_id')) {
        WP_CLI::debug("getting blog IDs with WP-function");

        $site_ids = get_sites(array('fields' => 'ids', 'network_id' => get_current_network_id()));
      } else {
        WP_CLI::debug("getting blog IDs with select-statement");

        $site_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs WHERE site_id = $wpdb->siteid;");
      }

      $blog_IDs = array_merge($blog_IDs, $site_ids);
    }

    WP_CLI::debug("Found " . count($blog_IDs) . " site ids");

    return $blog_IDs;
  }

  /**
   * 
   * @param int $blogID id of the blog
   */
  private function switchSiteContext(int $blogID)
  {
    if (is_multisite() === true) {
      switch_to_blog($blogID);
      WP_CLI::success("Swtiched site id to " . $blogID);
    } else {
      WP_CLI::log("No multisite -> no switch necessary.");
    }
  }

  /**
   * 
   */
  private function resetContext()
  {
    WP_CLI::debug("restoring blog...");
    if (is_multisite()) {
      restore_current_blog(); //WP function
    } else {
      WP_CLI::debug("No multisite - no restore needed");
    }
  }

  /**
   * 
   * @param string $type
   * @param string $table
   * @param string $local_uri
   * @param string $aws_uri
   * @param bool $revert
   * 
   * @return string update statement
   */
  private function updatePostContent(string $type, string $table, string $local_uri, string $aws_uri, $revert = false)
  {
    $from = (!$revert) ? $local_uri : $aws_uri;
    $to   = (!$revert) ? $aws_uri : $local_uri;

    WP_CLI::debug("UpdatePostContent 'TABLE': " . $table);
    WP_CLI::debug("UpdatePostContent 'FROM': " . $from);
    WP_CLI::debug("UpdatePostContent 'TO': " . $to);

    return "UPDATE $table SET post_content = replace(post_content, '$type=\"$from', '$type=\"$to');";
  }
}

WP_CLI::add_command('aws-s3-migrate', 'S3Migration_Command');
