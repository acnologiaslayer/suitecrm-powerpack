<?php
/**
 * Recording Proxy - Serves Twilio recordings
 * Requires valid SuiteCRM session
 */

// Check for valid session
define("sugarEntry", true);
chdir(dirname(__FILE__) . "/../..");

if (!file_exists("config.php")) {
    header("HTTP/1.0 500 Internal Server Error");
    exit;
}

require_once("include/entryPoint.php");

global $current_user;
if (empty($current_user) || empty($current_user->id)) {
    header("HTTP/1.0 401 Unauthorized");
    echo "Unauthorized";
    exit;
}

$file = isset($_REQUEST["file"]) ? basename($_REQUEST["file"]) : "";
$sid = isset($_REQUEST["sid"]) ? preg_replace("/[^a-zA-Z0-9]/", "", $_REQUEST["sid"]) : "";

if (!empty($file)) {
    $filepath = "upload/twilio_recordings/" . $file;
    if (file_exists($filepath) && preg_match("/\.mp3$/i", $file)) {
        header("Content-Type: audio/mpeg");
        header("Content-Length: " . filesize($filepath));
        header("Accept-Ranges: bytes");
        readfile($filepath);
        exit;
    }
}

if (!empty($sid)) {
    global $sugar_config;
    $accountSid = $sugar_config["twilio_account_sid"] ?? "";
    $authToken = $sugar_config["twilio_auth_token"] ?? "";

    if (!empty($accountSid) && !empty($authToken)) {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Recordings/{$sid}.mp3";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$accountSid}:{$authToken}");
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $audio = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            header("Content-Type: audio/mpeg");
            header("Content-Length: " . strlen($audio));
            echo $audio;
            exit;
        }
    }
}

header("HTTP/1.0 404 Not Found");
echo "Recording not found";
