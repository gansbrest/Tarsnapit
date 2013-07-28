## Update 

Recently I needed to add hourly option for my tarsnap backups, so I rewrote whole script to make it more general and abstract. Enjoy!

## Features

* In the current implementation tarsnapit works in **two** modes:

1. Backup mode (-b flag) - main mode used to create backups.

2. Cleanup mode (-d flag) - used to clean up old archives. By running tarsnapit in cleanup mode (usually once a day) we can read archives list just once and purge all required entries according to backup policy defined in the config.

* Tarsnap config (-c flag) - tarsnapit script uses config file to figure out which data to archive.

* Plugins support - in some cases you want to execute some commands before archiving phase, for example to do database backup. Plugins were designed to do just that. Read **Usage** section to get more info.

## How to use

Lets jump straight to examples:

First you need to create config file. Currently it's a json file. Here is config example:

    {
      "global": {
        "tarsnap_bin": "/usr/local/bin/tarsnap"
      },
      "bundles": {
        "daily": {
          "plugins": [
            "path_to_plugin/tarsnap_daily_plugin.php"
          ],  
          "groups": {
            "sites": [
              "/var/www"
            ]   
          },  
          "excludes": [
            "/var/www/site_name/files/site/imagecache",
          ],  
          "delete_after": "30days",
          "keep_at_least": "15days" 
        },  
        "hourly": {
          "plugins": [
              "path_to_plugin/tarsnap_hourly_plugin.php"
            ],  
          "groups": {
          },  
          "delete_after": "24hours",
          "keep_at_least": "12hours" 
        }   
      }
    }

Where **global** (required) section defines global tarsnap config options - at the moment just tarsnap binary.

Then there are **bundles** (required). In the example we have 2 bundles - **daily** and **hourly**. You can name you bundles however you like, just make sure you pass bundle name to tarsnapit -b option.

Each bundle could contain **plugins** (optional) section. Each plugin will be executed in defined order. We will talk about plugins a bit later.

**groups** (required) are used to locally separate files and folders to backup, you will see group names in the output of `tarnsap --list-archives` command, so make sure to give them readable names. That will help to identify correct files during restore phase.

**excludes** (optional) - used to exclude particular files or folders during archiving phase

**delete_after** (required) - controls puring strategy. During cleanup phase all archives from particular bundle will be compared against current date and those with creation time less than **delete_after** will be removed.

**keep_at_least** (required) - security check in case backup job will contain some errors. It will compare oldest archive entry with current time minus **keep_at_least** value. I think it's best to illustrate with code sample:

    if ($oldestArchiveDate < $secureDate) {
      return; // Keep at least $secureDateStr of backups :)
    }

Once you have config created, just schedule tarsnapit using crontab:

    # Tarsnap backup
    31 22 * * * /path/to/tarsnapit.php -c /path/to/config.json -b daily >> /var/log/crons/crons.txt 2>&1
    01 * * * /path/to/tarsnapit.php -c /path/to/config.json -b hourly >> /var/log/crons/crons.txt 2>&1
    # Tarsnap cleanup ( once a day )
    31 23 * * * /path/to/tarsnapit.php -c /path/to/config.json -d >> /var/log/crons/crons.txt 2>&1

That should be it.

### Plugins

Plugins are just classes and at the moment they don't implement interface ( todo? ).

* plugin class name should correspond to the plugin filepath defined in the config. Ex `path_to_plugin/tarsnap_daily_plugin.php -> class Tarsnap_Daily_Plugin`

* required property 
 * $name - will be used in debug output in case something goes wrong

* required methods
  * execute - will be executed before main archiving phase for the bundle, thus giving you an option to execute custom code ( for example take myslqdump and store it in some dir which then will be archived )

* optional methods
  * extendConfig - this is kind of interesting. The idea here is that your plugin could provide additional groups (directories or files) to archive. Lets go back to our mysqldump example for a second. Say we want to store generated sql file in the /tmp/mysql/dump directory and would like to backup that, but we don't want to hardcode it to the config, because config doesnt need to know about plugins internals ( of course hardcoding plugin path to main config would work, but we trying to avoid duplication and maintain abstraction ). To do that we just extend main configuration by defining **extendConfig** method:

        private $binlogs_location = '/tmp/mysqldump';

        public function extendConfig() {
          return array(
            'mysql_binlogs' => array($this->binlogs_location)
          );  
        }

    After that `/tmp/mysqldump` will be included during main backup phase. Neat!
  * cleanup - this method should do plugin cleanup and will be executed by tarsnapit after backup phase. Remove your tmp directories or other stuff here.

Let me know if I forgot something and share your feedback.
