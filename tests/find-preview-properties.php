<?php
/* SPDX-License-Identifier: AGPL-3.0-only
 * Run: php tests/find-preview-properties.php
 * Uses synthetic mail objects only; no configuration, backend or SQL is loaded.
 */
define('ZPUSH_VERSION', 'regression-test');
class ZLog {
    public static function Write($level, $message, $truncate = true) {}
    public static function IsWbxmlDebugEnabled() { return false; }
}
set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity === E_DEPRECATED) return true;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$_SERVER['SCRIPT_FILENAME'] = realpath(__DIR__ . '/../src/index.php');
require __DIR__ . '/../src/vendor/autoload.php';
function previewMail() {
    $data = fopen('php://temp', 'w+'); fwrite($data, 'preview'); rewind($data);
    return (object) ['subject' => 'synthetic', 'datereceived' => 946684800,
        'displayto' => '', 'cc' => 'cc@example.invalid', 'bcc' => 'bcc@example.invalid',
        'Displaycc' => 'legacy-cc@example.invalid', 'Displaybcc' => 'legacy-bcc@example.invalid',
        'importance' => 1, 'read' => 0, 'from' => 'sender@example.invalid',
        'asbody' => (object) ['data' => $data], 'attachments' => []];
}
function previewCheck($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$cases = [
    'standard recipient fields' => function () {
        $mail = previewMail(); unset($mail->Displaycc, $mail->Displaybcc);
        $result = SyncFindProperties::GetObjectFromSyncMail($mail);
        previewCheck($result->displaycc === $mail->cc && $result->displaybcc === $mail->bcc, 'Use SyncMail cc/bcc');
    },
    'legacy recipient fallback' => function () {
        $mail = previewMail(); unset($mail->cc, $mail->bcc);
        $result = SyncFindProperties::GetObjectFromSyncMail($mail);
        previewCheck($result->displaycc === $mail->Displaycc && $result->displaybcc === $mail->Displaybcc, 'Keep legacy values');
    },
    'absent body' => function () {
        $mail = previewMail(); unset($mail->asbody);
        previewCheck(SyncFindProperties::GetObjectFromSyncMail($mail)->preview === '', 'Missing body should have an empty preview');
    },
    'absent body data' => function () {
        $mail = previewMail(); $mail->asbody = (object) [];
        previewCheck(SyncFindProperties::GetObjectFromSyncMail($mail)->preview === '', 'Missing data should have an empty preview');
    },
    'string body' => function () {
        $mail = previewMail(); $mail->asbody->data = 'string preview';
        previewCheck(SyncFindProperties::GetObjectFromSyncMail($mail)->preview === 'string preview', 'String body should be supported');
    },
    'seekable body position' => function () {
        $mail = previewMail(); fseek($mail->asbody->data, 2);
        $result = SyncFindProperties::GetObjectFromSyncMail($mail);
        previewCheck($result->preview === 'eview', 'Read from the existing position');
        previewCheck(ftell($mail->asbody->data) === 2, 'Restore the original position');
    },
    'preview limit' => function () {
        $mail = previewMail(); $mail->asbody->data = str_repeat('a', 300);
        previewCheck(strlen(SyncFindProperties::GetObjectFromSyncMail($mail)->preview) === 254, 'Preserve the existing preview limit');
    },
    'body content unchanged' => function () {
        $mail = previewMail(); $text = '<b>literal</b>&amp;';
        ftruncate($mail->asbody->data, 0); fwrite($mail->asbody->data, $text); rewind($mail->asbody->data);
        previewCheck(SyncFindProperties::GetObjectFromSyncMail($mail)->preview === $text, 'Do not introduce HTML transformations');
    },
    'closed body stream' => function () {
        $mail = previewMail(); fclose($mail->asbody->data);
        previewCheck(SyncFindProperties::GetObjectFromSyncMail($mail)->preview === '', 'Closed stream should not raise a TypeError');
    },
    'modern attachments' => function () {
        $mail = previewMail(); $mail->asattachments = [(object) ['displayname' => 'synthetic.txt']];
        previewCheck(SyncFindProperties::GetObjectFromSyncMail($mail)->hasattachments === 1, 'Recognize AirSyncBase attachments');
    },
    'legacy attachments' => function () {
        $mail = previewMail(); $mail->attachments = [(object) ['displayname' => 'synthetic.txt']];
        previewCheck(SyncFindProperties::GetObjectFromSyncMail($mail)->hasattachments === 1, 'Preserve legacy attachment detection');
    },
    'optional fields missing' => function () {
        $result = SyncFindProperties::GetObjectFromSyncMail((object) []);
        previewCheck($result->subject === '' && $result->preview === '' && $result->hasattachments === 0, 'Missing optional fields should be safe');
    },
    'nonseekable body' => function () {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fwrite($pair[0], 'nonseekable'); stream_socket_shutdown($pair[0], STREAM_SHUT_WR);
        $mail = previewMail(); $mail->asbody->data = $pair[1];
        try {
            previewCheck(SyncFindProperties::GetObjectFromSyncMail($mail)->preview === 'nonseekable', 'Read without attempting a seek');
        }
        finally { fclose($pair[0]); fclose($pair[1]); }
    },
];
$results = []; $passed = true;
foreach ($cases as $name => $run) {
    try { $run(); $results[$name] = ['pass' => true]; }
    catch (Throwable $error) { $results[$name] = ['pass' => false, 'error' => $error->getMessage()]; $passed = false; }
}
restore_error_handler();
echo json_encode(['result' => $passed ? 'PASS' : 'FAIL', 'cases' => $results], JSON_PRETTY_PRINT) . "\n";
exit($passed ? 0 : 1);
