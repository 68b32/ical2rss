<?php
    $config = [];

    // Global configuration settings
    $config['global'] = [
        // Maximum allowed hours for feed generation (default: 1 year)
        'max_time_hours' => 24*365,
        
        // Full path to the plann executable (usually in a virtual environment)
        'plann_path' => '/path/to/plann/.venv/bin/plann',
        
        // Full path to the ical2rss.py script
        'ical2rss_path' => '/path/to/ical2rss/ical2rss.py',
        
        // Directory where RSS feeds will be cached
        'cachedir' => './rsscache',
        
        // Secret key for debug output. Only show detailed errors when this key is provided
        'debugkey' => 'CHANGE_THIS_TO_A_RANDOM_STRING',
    ];

    // Default values for all calendars and groups
    // These can be overridden in individual calendar or group configurations
    $config['calendars_defaults'] = [
        'title' => 'Calendar Events',
        'link' => 'https://example.com',
        'description' => 'Calendar Feed',
        'timezone' => 'Europe/Berlin',
        'cachetime' => 300,  // Cache validity time in seconds (default: 5 minutes)
    ];

    // Calendar configurations
    // Each calendar needs CalDAV access parameters and RSS feed metadata
    $config['calendars'][] = [
        // Unique identifier for the calendar, used in the URL
        'id' => 'example-calendar',
        
        // CalDAV server configuration (required)
        'caldav_url' => 'http://nextcloud.example.com/remote.php/dav',
        'caldav_username' => 'username',
        'caldav_password' => 'password',
        'calendar_url' => 'http://nextcloud.example.com/remote.php/dav/calendars/username/calendar-name/',
        
        // RSS feed metadata (overrides defaults)
        'title' => 'My Events',
        'link' => 'https://mywebsite.com/calendar',
        'description' => 'My Public Events Feed',
        'timezone' => 'Europe/Berlin',
        'cachetime' => 300,  // 5 minutes
    ];

    $config['calendars'][] = [
        'id' => 'another-calendar',
        'caldav_url' => 'http://nextcloud.example.com/remote.php/dav',
        'caldav_username' => 'another-user',
        'caldav_password' => 'another-password',
        'calendar_url' => 'http://nextcloud.example.com/remote.php/dav/calendars/another-user/another-calendar/',
        'title' => 'Another Calendar',
        'link' => 'https://mywebsite.com/another-calendar',
        'description' => 'Another Calendar Feed',
        // Using defaults for timezone and cachetime
    ];

    // Group configurations
    // Groups combine multiple calendars into a single feed
    $config['groups'][] = [
        // Unique identifier for the group, used in the URL
        'id' => 'combined-feed',
        
        // List of calendar IDs to include in this group
        'members' => ['example-calendar', 'another-calendar'],
        
        // RSS feed metadata for the combined feed (overrides defaults)
        // All these parameters are optional - will use calendars_defaults if not specified
        'title' => 'Combined Calendar Feed',
        'link' => 'https://mywebsite.com/combined',
        'description' => 'Events from multiple calendars',
        'timezone' => 'Europe/Berlin',
        'cachetime' => 300,
    ];

    // Example of a minimal group configuration
    // Uses all defaults from calendars_defaults
    $config['groups'][] = [
        'id' => 'minimal-group',
        'members' => ['example-calendar', 'another-calendar'],
    ];

?> 