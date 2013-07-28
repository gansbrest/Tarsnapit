#!/usr/bin/php
<?php

$options = getopt('c:b:d');

if (empty($options['c']) || (empty($options['b']) && !isset($options['d']))) {
  check_status(1, "ERROR! \nTarsnapit works in 2 modes: \nbackup mode: tarsnapit.php -c /path/to/conf -b bundle_name \ncleanup mode: tarsnapit.php -c /path/to/conf -d\n");
}

$json_str = file_get_contents($options['c']);
$config = json_decode($json_str, true);

$tarsnap_bin = $config['global']['tarsnap_bin'];

// Mode selection
if (isset($options['d'])) {
  # CLEANUP MODE
  //$packed_str = file_get_contents('/tmp/archives_list');
  if (!empty($packed_str)) {
    $archives_list = unserialize($packed_str);
  } else {
    exec($config['global']['tarsnap_bin'] . ' --list-archives | sort -t- -k 1,1nr -k 2,2nr -k 3,3nr -k 4,4nr -k 5,5nr -k 6,6nr', $archives_list);

    // DEBUG
    //Temporarily save output to file
    //if (!empty($archives_list)) {
      //file_put_contents('/tmp/archives_list', serialize($archives_list));
    //}
  }

  if (!empty($archives_list)) {

    // Allow plugins to extend config
    foreach($config['bundles'] as $bundle_name => $bundle_data_arr) { 
      $plugins_list = get_plugins_list($bundle_data_arr['plugins']);
      foreach($plugins_list as $plugin) {
        //  Load extra config
        $plugin_config_arr = plugin_call($plugin, 'extendConfig');
        if (!empty($plugin_config_arr)) {
          $config['bundles'][$bundle_name]['groups'] += $plugin_config_arr;
        }
      }

      // Doing cleanup
      foreach($config['bundles'][$bundle_name]['groups'] as $group_name => $folders_arr) {
        deleteOldArchives($archives_list, $bundle_name . '-' . $group_name, $bundle_data_arr['delete_after'], $bundle_data_arr['keep_at_least']);
      }
    }
  }
} else {
  # BACKUP MODE
  $config = $config['bundles'][$options['b']];
  if (!empty($config)) {

    // Create exclusions to be passed to tarsnap
    if (!empty($config['excludes'])) {
      $exclusions_file = '/tmp/tarsnap-' . $options['b'] . '-excludes';
      $bytes_wrtn = file_put_contents($exclusions_file, implode("\n", $config['excludes']));
      if ($bytes_wrtn > 0) {
        $status = 0;
      }
      check_status($status, 'Problem with exclusions file');
    }

    // Init plugins
    $plugins_list = get_plugins_list($config['plugins']);

    foreach($plugins_list as $plugin) {
      //  Load extra config
      $plugin_config_arr = plugin_call($plugin, 'extendConfig');
      if (!empty($plugin_config_arr)) {
        $config['groups'] += $plugin_config_arr;
      }

      // Execute plugin
      $status = plugin_call($plugin, 'execute');
      check_status($status, "Problem during $plugin->name execution.");
    }

    # Call tarsnap to create archive
    $archiveName = date('Y-m-d-H-i-s') . '-prod-' . $options['b'];

    foreach($config['groups'] as $archiveSuffix => $fNamesList) { 
      $cmd = $tarsnap_bin . ' -c -f ' . $archiveName . '-' . $archiveSuffix . ' -X ' . $exclusions_file . ' ' . implode(' ', $fNamesList);
      print $cmd . "\n";
      system($cmd, $status);

      check_status($status, 'Problem with ' . $archiveSuffix . ' group.');
    }

    # Tarsnapit cleanup
    unlink($exclusions_file);

    # Plugins cleanup
    foreach($plugins_list as $plugin) {
      plugin_call($plugin, 'cleanup');
    }
  }
}

function deleteOldArchives($archivesList, $filterName, $delete_after, $keep_at_least) {
  global $config;

  $backupItemsList = array();
  if (!empty($archivesList)) {
    foreach($archivesList as $backupItem) {
      if (preg_match("|$filterName|is", $backupItem)) {
        $backupItemsList[] = $backupItem;
      }
    }
  }

  if (empty($backupItemsList)) {
    echo 'No backup items - terminate';
    exit;
  }

  $latestBackupDate = new DateTime(extractArchiveDateStr($backupItemsList[0]));
  $maxAllowedDate = clone $latestBackupDate;
  $maxAllowedDate->modify('-' . $delete_after);

  // Security check to prevent backup removals in case of
  // backup system failures
  $lastArchiveItem = array_pop($backupItemsList);
  $oldestArchiveDate = new DateTime(extractArchiveDateStr($lastArchiveItem));
  array_push($backupItemsList, $lastArchiveItem);

  $currDate = new DateTime();
  $secureDate = clone $currDate;
  $secureDate->modify('-' . $keep_at_least);

  if ($oldestArchiveDate < $secureDate) {
    return; // Keep at least $secureDateStr of backups :)
  }

  // HELPFUL DEBUG OPTIONS
  //echo "Oldest " . $oldestArchiveDate->format('Y-m-d-H-i-s') . "\n";
  //echo "Secure date " . $secureDate->format('Y-m-d-H-i-s') . "\n";
  //echo __FILE__."<pre>"; print_r($oldestArchiveDate->format('Y-m-d-H-i-s')); "</pre>";
  //exit;

  ## Archives cleanup
  $archToRemoveList = array();
  foreach($backupItemsList as $archiveName) {
    $archiveDate = new DateTime(extractArchiveDateStr($archiveName));
    if ($archiveDate < $maxAllowedDate) {
      $archToRemoveList[] = $archiveName;
    }
  }

  if (!empty($archToRemoveList)) {
    system(TARSNAP_BIN . ' -d -f ' . implode(' -f ', $archToRemoveList));
  }
}

function extractArchiveDateStr($archiveName) {
  $time_str = substr($archiveName, 0, 19);
  $time_parts = explode('-', $time_str);
  return "$time_parts[0]-$time_parts[1]-$time_parts[2] $time_parts[3]:$time_parts[4]:$time_parts[5]";
}

function plugin_call($plugin, $method_name) {
  $result = '';
  if (is_callable(array($plugin, $method_name))) {
    $result = $plugin->$method_name();
  }
  return $result;
}

function get_plugins_list($plugin_files_list = array()) {
  $plugins_list = array();

  if (!empty($plugin_files_list)) {
    foreach($plugin_files_list as $plugin) {
      // Load plugin
      require_once($plugin);
      // Get plugin class name for initialization
      $plugin_class = get_plugin_class_name($plugin);

      $plugin = new $plugin_class();
      $plugins_list[] = $plugin;
    }
  }

  return $plugins_list;
}

function get_plugin_class_name($plugin) {
  $basename =  basename($plugin, '.php');
  $basename =  str_replace('_', ' ', $basename);
  $class_name =  ucwords($basename);
  $class_name =  str_replace(' ', '_', $class_name);
  return $class_name;
}

function check_status($status, $err_msg = '') {
  if ($status > 0) {
    if (!empty($err_msg)) {
      echo "$err_msg\n";
    }
    exit($status);
  }
}

?>
