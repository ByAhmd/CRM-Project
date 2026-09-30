<?php

declare(strict_types=1);

// Backups (decision D-16): System → Backups, the failure notice, the failure reasons.
return [

    'navigation' => 'Backups',

    'pages' => [
        'title' => 'Backups',
        'last_failure' => 'The last backup (started :time) failed: :reason',
    ],

    'fields' => [
        'created_at' => 'Taken at',
        'database' => 'Database',
        'files' => 'Files',
        'total' => 'Total',
    ],

    'helpers' => [
        'schedule' => '{1} A backup is taken automatically every Thursday at 22:00 and only the newest set is kept; older sets are deleted after each successful backup. The hosting provider\'s own backups remain a separate layer.|[2,*] A backup is taken automatically every Thursday at 22:00 and the newest :count sets are kept; older sets are deleted after each successful backup. The hosting provider\'s own backups remain a separate layer.',
    ],

    'actions' => [
        'back_up_now' => 'Back up now',
        'download_database' => 'Database',
        'download_files' => 'Files',
    ],

    'confirmations' => [
        'back_up_now' => [
            'heading' => 'Back up now?',
            'description' => 'A backup of the database and the stored files starts within a minute and runs in the background. It appears in this list once it is complete; older sets beyond the kept number are then deleted.',
        ],
    ],

    'notifications' => [
        'queued_title' => 'Backup queued',
        'queued_body' => 'It starts within a minute. Refresh this page later to see the new set.',
        'failed_title' => 'The backup failed',
        'failed_body' => 'The backup started :time did not complete. :reason The earlier backups are unchanged.',
        'failed_mail_hint' => 'The technical details are on the Backups page in the System section. Until a backup succeeds, the newest complete set is the one to restore from.',
        'greeting' => 'Hello :name,',
        'open' => 'Open backups',
    ],

    'empty' => [
        'heading' => 'No backups yet',
        'description' => 'The first backup is taken on Thursday at 22:00, or right away with "Back up now".',
    ],

    'reasons' => [
        'location' => 'The backup folder is unsafe or cannot be written to.',
        'database' => 'The database could not be exported.',
        'files' => 'The stored files could not be archived.',
        'incomplete' => 'A backup file was missing or empty.',
        'unexpected' => 'An unexpected error stopped the backup.',
    ],

    // The exact cause under the reason on System → Backups (also, in English, the log and crm:backup output).
    // :output and :error carry the tool's own message, shown as the tool wrote it.
    'details' => [
        'location_public' => 'The backup directory :directory lies under public/ — the database would be downloadable without authorisation.',
        'location_private_disk' => 'The backup directory :directory lies inside the private disk it archives (:root).',
        'location_public_disk' => 'The backup directory :directory lies inside the public disk, which public/storage serves (:root).',
        'directory_create' => 'The backup directory :directory cannot be created.',
        'directory_writable' => 'The backup directory :directory is not writable.',
        'set_exists' => 'A backup set named :id already exists in :directory.',
        'set_folder' => 'The set folder :path cannot be created.',
        'rename' => 'The finished set could not be renamed from :from to :to.',
        'file_missing' => ':file is missing or empty after the run.',
        'unreadable' => 'The finished set :id cannot be read back.',
        'zlib_missing' => 'The PHP zlib extension is not loaded; the dump cannot be compressed.',
        'driver' => 'The default connection ":connection" uses the ":driver" driver; only MySQL and MariaDB are backed up.',
        'no_database' => 'The connection ":connection" names no database.',
        'line_break' => 'The database :option contains a line break and cannot be passed to mysqldump.',
        'defaults_create' => 'A temporary defaults file for mysqldump cannot be created under storage/framework.',
        'defaults_write' => 'The temporary defaults file for mysqldump cannot be written.',
        'tool_not_run' => ':tool could not be run: :error',
        'tool_failed' => ':tool exited with code :code: :output',
        'no_dump' => ':tool reported success but wrote no dump.',
        'compress_open' => 'The dump cannot be opened for compression.',
        'compress_read' => 'The dump cannot be read for compression.',
        'compress_write' => 'The compressed dump cannot be written (disk full?).',
        'private_disk_root' => 'The private disk (filesystems.disks.local) has no root directory.',
        'private_disk_create' => 'The private disk root :root does not exist and cannot be created.',
        'unexpected' => ':exception: :message',
    ],

];
