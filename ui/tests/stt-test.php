<?php
session_start();
error_reporting(E_ALL);

require_once(__DIR__.DIRECTORY_SEPARATOR."../profile_loader.php");
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lib".DIRECTORY_SEPARATOR."logger.php");

$TITLE = "SYNTH - STT Test - SYNTH Server";
$HIDE_NAVBAR = true;
$BODY_CLASS = 'synth-embedded';

ob_start();

include(__DIR__.DIRECTORY_SEPARATOR."../tmpl/head.html");

$debugPaneLink = false;

$enginePath = dirname(__FILE__) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "runtime_bootstrap.php");
synthRuntimeBootstrap($enginePath, [
 'load_general_settings' => true,
 'load_stt_connector' => true,
]);

function sttTestJsonEncode($value): string
{
 $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
 if ($encoded === false) {
 return print_r($value, true);
 }

 return $encoded;
}

function sttTestCollectDebugInfo(string $testFile): array
{
 $driver = strval($GLOBALS['STTFUNCTION'] ?? 'none');
 $info = [
 'service' => $driver,
 'active_connector_id' => function_exists('synthGetGeneralSettingInt')
 ? synthGetGeneralSettingInt('GLOBAL_STT_CONNECTOR_ID', 0)
 : 0,
 'test_file' => $testFile,
 'test_file_exists' => is_file($testFile),
 'test_file_readable' => is_readable($testFile),
 'test_file_size' => is_file($testFile) ? intval(filesize($testFile)) : 0,
 ];

 if ($driver === 'localwhisper') {
 $info['localwhisper'] = [
 'url' => trim(strval($GLOBALS['STT']['LOCALWHISPER']['URL'] ?? '')),
 'form_field' => trim(strval($GLOBALS['STT']['LOCALWHISPER']['FORMFIELD'] ?? 'file')),
 'timeout_seconds' => intval($GLOBALS['STT']['LOCALWHISPER']['TIMEOUT'] ?? 60),
 ];
 }

 $diagnostic = $GLOBALS['STT_LAST_DIAGNOSTIC'] ?? [];
 if (is_array($diagnostic) && !empty($diagnostic)) {
 $info['runtime_diagnostic'] = $diagnostic;
 }

 return $info;
}

// Optional local sample; none is distributed because no redistributable recording is available.
$defaultTestFile = $enginePath . "debug" . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "test.wav";
$missingSampleMessage = 'No local STT test sample is installed. Place a short English WAV at debug/data/test.wav '
 . '(ignored by Git), or run php ui/tests/stt-test.php <file.wav> from the server directory.';

// Include the appropriate STT function based on configuration
if ($STTFUNCTION == "azure") {
 require_once($enginePath . "stt" . DIRECTORY_SEPARATOR . "stt-azure.php");
} else if ($STTFUNCTION == "whisper") {
 require_once($enginePath . "stt" . DIRECTORY_SEPARATOR . "stt-whisper.php");
} else if ($STTFUNCTION == "localwhisper") {
 require_once($enginePath . "stt" . DIRECTORY_SEPARATOR . "stt-localwhisper.php");
} else if ($STTFUNCTION == "deepgram") {
 require_once($enginePath . "stt" . DIRECTORY_SEPARATOR . "stt-deepgram.php");
} else if (file_exists($enginePath . "stt" . DIRECTORY_SEPARATOR . "stt-{$STTFUNCTION}.php")){
 require_once($enginePath . "stt" . DIRECTORY_SEPARATOR . "stt-{$STTFUNCTION}.php");

} else {
 require_once($enginePath . "stt" . DIRECTORY_SEPARATOR . "stt-none.php");
 //$error_message = "Unknown STT function: $STTFUNCTION";
}

ini_set('display_errors', 1);

$transcription = '';
$error_message = '';

if (php_sapi_name() != "cli") {
 // Not running from CLI, so display as web page
 ?><link rel="stylesheet" href="../css/main.css"><style>

 h1, h2, h3, .header {
 color: #ffffff;
 }

 .status {
 margin-bottom: 15px;
 background-color: #3a3a3a;
 padding: 10px;
 border-radius: 5px;
 border: 1px solid #555555;
 }

 .status .label {
 font-weight: bold;
 color: #f8f9fa;
 }

 .status .ok {
 color: #28a745;
 }

 .status .error {
 color: #dc3545;
 }

 pre {
 background-color: #3a3a3a;
 padding: 15px;
 border: 1px solid #555555;
 overflow: auto;
 color: #f8f9fa;
 border-radius: 5px;
 }

 .response {
 font-weight: bold;
 color: #f8f9fa;
 background-color: #3a3a3a;
 padding: 15px;
 border-radius: 5px;
 border: 1px solid #555555;
 margin-top: 10px;
 }

 .section {
 margin-bottom: 30px;
 }

 .header {
 font-size: 24px;
 margin-bottom: 20px;
 }

 .divider {
 border-bottom: 1px solid #555555;
 margin: 20px 0;
 }

 .message {
 background-color: #444444;
 padding: 10px;
 border-radius: 5px;
 border: 1px solid #555555;
 max-width: 800px;
 margin-bottom: 20px;
 color: #f8f9fa;
 }

 .message p {
 margin: 0;
 }

 .error-message {
 color: #dc3545;
 font-weight: bold;
 }

 .info-message {
 color: #17a2b8;
 font-weight: bold;
 }

 .accuracy {
 color: #ffc107;
 font-weight: bold;
 }
 </style><main class="indent5"><h1>SYNTH Speech-to-Text Test</h1><div class="section"><?php
 $testFile = $defaultTestFile;
 $testFileAvailable = is_file($testFile) && is_readable($testFile);

 if (!$testFileAvailable) {
 // Report the missing sample once and make no provider or audio request.
 $transcription = '';
 echo '<div class="status"><span class="label error">STT test unavailable</span></div>';
 echo '<div class="error-message">' . htmlspecialchars($missingSampleMessage) . '</div>';
 } else {
 echo '<div class="status"><span class="label">Sending the local prerecorded test audio...</span></div>';
 echo '<div class="message">Sending <code>' . htmlspecialchars($testFile) . '</code> <button type="button" onclick="playAudio()" aria-label="Play test audio" style="background: none; color: #4CAF50; border: none; padding: 0; cursor: pointer; margin-left: 10px; font-size: 20px;">&#9654;</button></div>';
 echo '<audio id="testAudio" preload="none"><source src="../../debug/data/test.wav" type="audio/wav"></audio>';
 echo '<script>
 function playAudio() {
 var audio = document.getElementById("testAudio");
 audio.currentTime = 0;
 audio.play();
 }
 </script>';
 echo '<pre>' . htmlspecialchars(sttTestJsonEncode(sttTestCollectDebugInfo($testFile))) . '</pre>';

 echo '<div class="status"><span class="label">Obtaining transcription from STT service...</span></div>';
 $transcription = stt($testFile);

 if (!empty($transcription)) {
 echo '<div class="status"><span class="label ok">Transcription Successful!</span></div>';
 echo '<div class="response">Output: ' . nl2br(htmlspecialchars($transcription)) . '</div>';
 echo '<div class="message">Compare this output with what is spoken in your own recording.</div>';

 // Show detected tone if available
 $detectedTone = function_exists('getPlayerTone') ? getPlayerTone(30) : null;
 if (!empty($detectedTone)) {
 echo '<div class="message"><strong style="color: #17a2b8;">Detected tone: ' . htmlspecialchars($detectedTone) . '</strong></div>';
 }
 } else if ($STTFUNCTION == "none") {
 echo '<div class="status"><span class="label error">Transcription not possible</span></div>';
 echo '<div class="error-message">Transcription service is not selected.</div>';
 } else {
 echo '<div class="status"><span class="label error">Transcription Failed</span></div>';
 echo '<div class="error-message">Transcription failed or returned an empty result.</div>';
 echo '<pre>' . htmlspecialchars(sttTestJsonEncode(sttTestCollectDebugInfo($testFile))) . '</pre>';
 }
 }

 echo '<div class="message">Service used: <strong>' . htmlspecialchars($GLOBALS['STTFUNCTION']) . '</strong></div>';

 echo '</div>'; // End of section

 echo '<div class="section">';
 echo '<div class="divider"></div>';
 echo '<div style="font-size: 16px; line-height: 1.5; margin-bottom: 20px;"><span style="font-weight: bold; color: yellow; font-size: 18px;">
 IF THE OUTPUT MATCHES YOUR RECORDING, THEN THE STT SERVER WORKS!
 </span><br><b>This test uses a local WAV and does not test microphone capture inside Fallout.</b><br><b>SYNTH detects the active microphone automatically during the first in-game recording and remembers the working device.</b></div>';
 echo '</div>';
 ?></main><?php
 include(__DIR__.DIRECTORY_SEPARATOR."../tmpl/footer.html");

 $buffer = ob_get_contents();
 ob_end_clean();
 $title = $TITLE;
 $buffer = preg_replace('/(<title>)(.*?)(<\/title>)/i', '$1' . $title . '$3', $buffer);
 echo $buffer;
} else {
 // Running from CLI
 if (!isset($argv[1]) && !is_file($defaultTestFile)) {
 echo $missingSampleMessage . PHP_EOL;
 exit(1);
 } else if (!isset($argv[1])) {
 echo "Sending " . $defaultTestFile . PHP_EOL;
 echo sttTestJsonEncode(sttTestCollectDebugInfo($defaultTestFile)) . PHP_EOL;
 echo "Obtaining transcription from STT service.... ";

 $transcription = stt($defaultTestFile);
 echo $transcription . PHP_EOL;
 echo "Service used: ";
 print_r($GLOBALS["STTFUNCTION"] . PHP_EOL);
 if (empty($transcription)) {
 echo sttTestJsonEncode(sttTestCollectDebugInfo($defaultTestFile)) . PHP_EOL;
 }

 echo PHP_EOL;
 echo "IF THE TRANSCRIPT MATCHES WHAT IS SPOKEN IN YOUR RECORDING, THEN STT WORKS!" . PHP_EOL;
 echo "If you are still having issues in-game, then check that your microphone is set as default in your system settings." . PHP_EOL;
 } else {
 echo stt($argv[1]) . PHP_EOL;
 }
}
?>
