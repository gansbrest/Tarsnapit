#!/usr/bin/php

<?php


################ CONFIG ##########################

define('TARSNAP_BIN', '/usr/local/bin/tarsnap');
define('DB_BACKUP_DIR', '/usr/local/backup/');
define('EXCLUSIONS_FILE', '/usr/local/backup/exclusions.txt');
define('DELETE_OLD_ARCH_AFTER', '30days');
define('KEEP_AT_LEAST', '15days');

# Perform mysql backups
#
#   Example:
#
#   'db1' => array(
#       'username' => 'user1',
#       'pass' => 'pass1',
#    ),
#
$dbsList = array();

# Files and folders to archive
#
#   Example:
#
#  'system' => array(
#    '/etc',
#    '/var/spool',
#    '/usr/share/munin',
#  ),
#  'homedirs' => array(
#    '/home/username',
#    '/home/git',
#  ),
$filesAndFoldersArr = array();

# Files and folders to exclude from backup 
#
#   Example:
#
#   '/var/www/site1/files/site1/imagecache',
#
#
$excludesArr = array();



#################### END CONFIG ########################

if (count($dbsList)) {
  foreach($dbsList as $dbName => $dbDataArr) {
    system('mysqldump -u ' . $dbDataArr['username'] . ' -p'. $dbDataArr['pass'] . ' ' . $dbName . ' > ' . DB_BACKUP_DIR . $dbName . '.sql', $dbStatus);
  }
}

$status = file_put_contents(EXCLUSIONS_FILE, implode("\n", $excludesArr));
$excludeStatus = $status ? 0 : 1;

# Call tarsnap to create archive
$archiveName = date('Y-m-d') . '-prod';
$tarsnapError = 1; // Tarsnap should reset this flag later

// Check for errors in previous steps
if (empty($dbStatus) && empty($excludeStatus) && !empty($filesAndFoldersArr)) {
  foreach($filesAndFoldersArr as $archiveSuffix => $fNamesList) { 
    $cmd = TARSNAP_BIN . ' -c -f ' . $archiveName . '-' . $archiveSuffix . ' -X ' . EXCLUSIONS_FILE . ' ' . implode(' ', $fNamesList);
    print $cmd . "\n";
    system($cmd, $tarsnapStatus);

    // Errors during execution
    if (!empty($tarsnapStatus)) {
      $tarsnapError = 1;
    }
  }
} else {
  print 'Db status - ' . $dbStatus . "\n";
  print 'Exclude status - '. $excludeStatus . "\n";
}

# Cleanup
if (empty($tarsnapError)) {
  system('rm -rf ' . DB_BACKUP_DIR . '*');
  deleteOldArchives(DELETE_OLD_ARCH_AFTER);
}

function deleteOldArchives($dateStr, $secureDateStr = KEEP_AT_LEAST) {

  exec(TARSNAP_BIN . ' --list-archives | sort -t- -k 1,2nr -k 2,3nr -k 3,4nr', $archivesList);

  $latestBackupDate = new DateTime(extractArchiveDateStr($archivesList[0]));
  $maxAllowedDate = clone $latestBackupDate;
  $maxAllowedDate->modify('-' . $dateStr);

  // Security check to prevent backup removals in case 
  // some backup system failures
  $lastArchiveItem = array_pop($archivesList);
  $oldestArchiveDate = new DateTime(extractArchiveDateStr($lastArchiveItem));
  array_push($archivesList, $lastArchiveItem);

  $currDate = new DateTime();
  $secureDate = clone $currDate;
  $secureDate->modify('-' . $secureDateStr);

  if ($oldestArchiveDate < $secureDateStr) {
    return; // Keep at least $secureDateStr of backups :)
  }

  foreach($archivesList as $archiveName) {
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
  return substr($archiveName, 0, 10);
}

?>
