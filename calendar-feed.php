<?php

require('config.php');

// Add this helper function after the require statement
function serve_cached_with_error($cache_file, $error_message, $config) {
    $content = file_get_contents($cache_file);
    
    // Only add error message if debug key matches
    if (isset($_GET['debug']) && $_GET['debug'] === $config['global']['debugkey']) {
        // Insert error comment after the XML declaration
        $content = preg_replace(
            '/^(<\?xml[^>]+>\s*)/',
            '$1<!-- Warning: Using expired cache. Error generating fresh content: ' . htmlspecialchars($error_message) . " -->\n",
            $content
        );
    } else {
        // Add generic message without error details
        $content = preg_replace(
            '/^(<\?xml[^>]+>\s*)/',
            '$1<!-- Warning: Using expired cache -->\n',
            $content
        );
    }
    
    header('Content-Type: application/xml');
    echo $content;
    exit;
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
foreach ($config['calendars'] as $cal) {
    if ($cal['id'] === $calendar_id) {
        $calendar_config = $cal;
        break;
    }
}

if ($calendar_config === null) {
    http_response_code(404);
    die('Error: Calendar not found');
}

// Validate hours parameter
if (!is_numeric($hours) || $hours <= 0 || $hours > $config['global']['max_time_hours']) {
    http_response_code(400);
    die(sprintf('Error: Hours must be between 1 and %d', $config['global']['max_time_hours']));
}

// Merge defaults with calendar-specific config
$calendar_config = array_merge($config['calendars_defaults'], $calendar_config);

// Validate required parameters
$required_params = ['caldav_url', 'caldav_username', 'caldav_password', 'calendar_url', 
                   'title', 'link', 'description', 'timezone'];

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

$cache_exists = file_exists($cache_file);
$cache_expired = false;

if ($cache_exists) {
    $cache_age = time() - filemtime($cache_file);
    if ($cache_age <= $calendar_config['cachetime']) {
        // Cache is still valid
        header('Content-Type: application/xml');
        readfile($cache_file);
        exit;
    }
    $cache_expired = true;
}

// Prepare commands
$plann_cmd = sprintf(
    '%s --caldav-url %s --caldav-username %s --caldav-password %s --calendar-url %s select --start="now" --end="+%dhours" print-ical',
    escapeshellarg($config['global']['plann_path']),
    escapeshellarg($calendar_config['caldav_url']),
    escapeshellarg($calendar_config['caldav_username']),
    escapeshellarg($calendar_config['caldav_password']),
    escapeshellarg($calendar_config['calendar_url']),
    $hours
);

$ical2rss_cmd = sprintf(
    '%s --channel-title %s --channel-link %s --channel-description %s --timezone %s',
    escapeshellarg($config['global']['ical2rss_path']),
    escapeshellarg($calendar_config['title']),
    escapeshellarg($calendar_config['link']),
    escapeshellarg($calendar_config['description']),
    escapeshellarg($calendar_config['timezone'])
);

// Execute commands
$descriptorspec = array(
    0 => array("pipe", "r"),  // stdin
    1 => array("pipe", "w"),  // stdout
    2 => array("pipe", "w")   // stderr
);

// Start plann process
$plann_process = proc_open($plann_cmd, $descriptorspec, $plann_pipes);
if (!is_resource($plann_process)) {
    if ($cache_exists) {
        serve_cached_with_error($cache_file, 'Failed to execute plann command', $config);
    }
    http_response_code(500);
    die('Error: Failed to execute plann command');
}

// Start ical2rss.py process
$ical2rss_process = proc_open($ical2rss_cmd, $descriptorspec, $ical2rss_pipes);
if (!is_resource($ical2rss_process)) {
    proc_close($plann_process);
    if ($cache_exists) {
        serve_cached_with_error($cache_file, 'Failed to execute ical2rss.py command', $config);
    }
    http_response_code(500);
    die('Error: Failed to execute ical2rss.py command');
}

// Pipe plann output to ical2rss.py
stream_copy_to_stream($plann_pipes[1], $ical2rss_pipes[0]);
fclose($plann_pipes[1]);
fclose($ical2rss_pipes[0]);

// Get output and errors
$output = stream_get_contents($ical2rss_pipes[1]);
$plann_error = stream_get_contents($plann_pipes[2]);
$ical2rss_error = stream_get_contents($ical2rss_pipes[2]);

// Close processes
$plann_return = proc_close($plann_process);
$ical2rss_return = proc_close($ical2rss_process);

// Check for errors
if ($plann_return !== 0) {
    if ($cache_exists) {
        serve_cached_with_error($cache_file, 'Error executing plann: ' . $plann_error, $config);
    }
    http_response_code(500);
    die('Error executing plann: ' . $plann_error);
}

if ($ical2rss_return !== 0) {
    if ($cache_exists) {
        serve_cached_with_error($cache_file, 'Error executing ical2rss.py: ' . $ical2rss_error, $config);
    }
    http_response_code(500);
    die('Error executing ical2rss.py: ' . $ical2rss_error);
}

// Validate XML
$prev_use_errors = libxml_use_internal_errors(true);
$xml = simplexml_load_string($output);
if ($xml === false) {
    if ($cache_exists) {
        $xml_errors = libxml_get_errors();
        $error_msg = 'Invalid XML output generated: ';
        foreach ($xml_errors as $error) {
            $error_msg .= sprintf("[Line %d] %s", $error->line, $error->message);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev_use_errors);
        serve_cached_with_error($cache_file, $error_msg, $config);
    }
    $xml_errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev_use_errors);
    http_response_code(500);
    die('Error: Invalid XML output generated');
}
libxml_use_internal_errors($prev_use_errors);

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