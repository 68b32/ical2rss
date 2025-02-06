<?php

// Load and validate configuration
$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    http_response_code(500);
    die('Error: Configuration file not found');
}

$config_json = file_get_contents($config_file);
if ($config_json === false) {
    http_response_code(500);
    die('Error: Could not read configuration file');
}

$config = json_decode($config_json, true);
if ($config === null) {
    http_response_code(500);
    die('Error: Invalid JSON in configuration file');
}

function query_plann_calendars($calendars, $hours) {
    global $config;
    $all_events = '';
    
    // Required parameters for CalDAV access
    $required_params = ['caldav_url', 'caldav_username', 'caldav_password', 'calendar_url'];
    
    foreach ($calendars as $calendar) {
        // Validate required parameters for this calendar
        foreach ($required_params as $param) {
            if (empty($calendar[$param])) {
                http_response_code(500);
                die(sprintf('Error: Missing required CalDAV parameter "%s" for calendar "%s"', 
                    $param, $calendar['id']));
            }
        }
        
        // Prepare plann command
        $plann_cmd = sprintf(
            '%s --caldav-url %s --caldav-username %s --caldav-password %s --calendar-url %s select --start="now" --end="+%dhours" print-ical',
            escapeshellarg($config['global']['plann_path']),
            escapeshellarg($calendar['caldav_url']),
            escapeshellarg($calendar['caldav_username']),
            escapeshellarg($calendar['caldav_password']),
            escapeshellarg($calendar['calendar_url']),
            $hours
        );
        
        // Execute plann command
        $descriptorspec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "w")   // stderr
        );

        $plann_process = proc_open($plann_cmd, $descriptorspec, $pipes);
        if (!is_resource($plann_process)) {
            http_response_code(500);
            die('Error: Failed to execute plann command for calendar: ' . $calendar['id']);
        }

        // Get output and errors
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        
        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);

        // Check return value
        $return_value = proc_close($plann_process);
        if ($return_value !== 0) {
            http_response_code(500);
            die('Error executing plann for calendar ' . $calendar['id'] . ': ' . $error);
        }

        if(!empty($output))
            $all_events .= "\n\n".$output;

    }
    
    return $all_events;
}

// Validate input parameters
if (!isset($_GET['calendar']) || !isset($_GET['hours'])) {
    http_response_code(400);
    die('Error: Missing required parameters "calendar" and "hours"');
}

$calendar_id = $_GET['calendar'];
$hours = intval($_GET['hours']);

// Find calendar config by id
$calendar_config = null;
$calendars_to_query = [];

// First check for individual calendar
foreach ($config['calendars'] as $cal) {
    if ($cal['id'] === $calendar_id) {
        $calendar_config = array_merge($config['calendars_defaults'], $cal);
        $calendars_to_query[] = $calendar_config;
        break;
    }
}

// If no calendar found, check for group
if (empty($calendars_to_query)) {
    foreach ($config['groups'] as $group) {
        if ($group['id'] === $calendar_id) {
            // Found a group, collect all member calendars
            foreach ($group['members'] as $member_id) {
                $member_found = false;
                foreach ($config['calendars'] as $cal) {
                    if ($cal['id'] === $member_id) {
                        $member_found = true;
                        $calendars_to_query[] = array_merge($config['calendars_defaults'], $cal);
                        break;
                    }
                }
                if (!$member_found) {
                    http_response_code(500);
                    die(sprintf('Error: Calendar "%s" from group "%s" not found', $member_id, $calendar_id));
                }
            }
            
            // Use group metadata or defaults for the feed
            $calendar_config = array_merge(
                $config['calendars_defaults'],
                array_intersect_key($group, array_flip(['title', 'link', 'description', 'timezone']))
            );
            break;
        }
    }
}

// If neither calendar nor group found
if (empty($calendars_to_query)) {
    http_response_code(404);
    die('Error: Calendar or group not found');
}

// Validate hours parameter
if (!is_numeric($hours) || $hours <= 0 || $hours > $config['global']['max_time_hours']) {
    http_response_code(400);
    die(sprintf('Error: Hours must be between 1 and %d', $config['global']['max_time_hours']));
}



$required_params = ['title', 'link', 'description', 'timezone'];

foreach ($required_params as $param) {
    if (empty($calendar_config[$param])) {
        http_response_code(500);
        die(sprintf('Error: Missing required configuration parameter "%s"', $param));
    }
}

// Check cache
$cache_file = sprintf('%s/%s_%dhours.xml', 
    rtrim($config['global']['cachedir'], '/'),
    $calendar_id,
    $hours
);

if (file_exists($cache_file)) {
    $cache_age = time() - filemtime($cache_file);
    if ($cache_age <= $calendar_config['cachetime']) {
        // Cache is still valid
        header('Content-Type: application/xml');
        readfile($cache_file);
        exit;
    }
}

// Get iCal data from plann
$ical_data = query_plann_calendars($calendars_to_query, $hours);

// Prepare ical2rss command
$ical2rss_cmd = sprintf(
    '%s --channel-title %s --channel-link %s --channel-description %s --timezone %s',
    escapeshellarg($config['global']['ical2rss_path']),
    escapeshellarg($calendar_config['title']),
    escapeshellarg($calendar_config['link']),
    escapeshellarg($calendar_config['description']),
    escapeshellarg($calendar_config['timezone'])
);

// Execute ical2rss
$descriptorspec = array(
    0 => array("pipe", "r"),  // stdin
    1 => array("pipe", "w"),  // stdout
    2 => array("pipe", "w")   // stderr
);

$ical2rss_process = proc_open($ical2rss_cmd, $descriptorspec, $pipes);
if (!is_resource($ical2rss_process)) {
    http_response_code(500);
    die('Error: Failed to execute ical2rss.py command');
}

// Feed iCal data to ical2rss
fwrite($pipes[0], $ical_data);
fclose($pipes[0]);

// Get output and errors
$output = stream_get_contents($pipes[1]);
$ical2rss_error = stream_get_contents($pipes[2]);

// Close pipes and process
fclose($pipes[1]);
fclose($pipes[2]);
$ical2rss_return = proc_close($ical2rss_process);

if ($ical2rss_return !== 0) {
    http_response_code(500);
    die('Error executing ical2rss.py: ' . $ical2rss_error);
}

// Validate XML
libxml_use_internal_errors(true);
$xml = simplexml_load_string($output);
if ($xml === false) {
    http_response_code(500);
    die('Error: Invalid XML output generated');
}

// Cache the result
if (!file_exists($config['global']['cachedir'])) {
    mkdir($config['global']['cachedir'], 0777, true);
}

// Add generation timestamp comment to the output
$timestamp = date('Y-m-d H:i:s T');
$output = preg_replace(
    '/^(<\?xml[^>]+>\s*)/',
    '$1<!-- Generated: ' . $timestamp . " -->\n",
    $output
);

file_put_contents($cache_file, $output);

// Output the feed
header('Content-Type: application/xml');
echo $output; 