<?php
/* SPDX-License-Identifier: AGPL-3.0-only
 * Regression coverage for Find response ranges. No real backend, configuration,
 * SQL connection or mailbox authentication is used.
 * Run: php tests/find-response-ranges.php
 */
ob_start();
define('ZPUSH_VERSION', 'regression-test');
class ZLog {
    public static function Write($level, $message, $truncate = true) {}
    public static function IsWbxmlDebugEnabled() { return false; }
    public static function GetWBXMLDebugInfo() { return ''; }
}
set_error_handler(function ($severity, $message, $file, $line) {
    // Existing Serializable-interface deprecations are outside this regression.
    if ($severity === E_DEPRECATED) return true;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$source = realpath(__DIR__ . '/../src');
$_SERVER['SCRIPT_FILENAME'] = $source . '/index.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_AUTH_USER'] = 'synthetic@example.invalid';
$_SERVER['HTTP_MS_ASPROTOCOLVERSION'] = '16.1';
$_GET = ['Cmd' => 'Find', 'User' => 'synthetic@example.invalid',
    'DeviceId' => 'syntheticrange', 'DeviceType' => 'iPhone'];
chdir($source);
require 'vendor/autoload.php';
Request::Initialize();
Request::ProcessHeaders();

function rangeStream($data = '') {
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $data); rewind($stream); return $stream;
}
function rangeInput($range) {
    $output = rangeStream(); $encoder = new WBXMLEncoder($output);
    $encoder->startWBXML();
    $value = function ($tag, $text) use ($encoder) {
        $encoder->startTag($tag); $encoder->content($text); $encoder->endTag();
    };
    $encoder->startTag(SYNC_FIND_FIND); $value(SYNC_FIND_SEARCHID, 'synthetic');
    $encoder->startTag(SYNC_FIND_EXECUTESEARCH);
    $encoder->startTag(SYNC_FIND_MAILBOXSEARCHCRITERION);
    $encoder->startTag(SYNC_FIND_QUERY); $value(SYNC_FOLDERTYPE, 'Email');
    $value(SYNC_FOLDERID, 'client-inbox'); $value(SYNC_FIND_FREETEXT, 'synthetic');
    $encoder->endTag();
    if ($range !== null) {
        $encoder->startTag(SYNC_FIND_OPTIONS); $value(SYNC_FIND_RANGE, $range); $encoder->endTag();
    }
    $encoder->endTag(); $encoder->endTag(); $encoder->endTag();
    rewind($output); return $output;
}
class RangeSearchProvider {
    public $range; public $returned; public $total; public $includeRange;
    public function GetMailboxSearchResults($parameters) {
        $rows = ['searchtotal' => $this->total];
        if ($this->includeRange) $rows['range'] = $this->range;
        for ($i = 0; $i < $this->returned; $i++) {
            $rows[] = ['class' => 'Email', 'longid' => 'synthetic:' . $i, 'serverid' => (string) $i];
        }
        return $rows;
    }
}
class RangeBackend {
    public $provider;
    public function GetSearchProvider() { return $this->provider; }
    public function Fetch($folder, $id, $parameters) {
        return (object) [
            'subject' => 'synthetic', 'datereceived' => 946684800, 'displayto' => '',
            'Displaycc' => '', 'Displaybcc' => '', 'importance' => 1, 'read' => 0,
            'asbody' => (object) ['data' => rangeStream('preview')],
            'attachments' => [], 'from' => 'sender@example.invalid',
            'ParentSourceKey' => hex2bin('a1b2'),
        ];
    }
}
class RangeDeviceManager {
    public function GetBackendIdForFolderId($id) { return 'backend-inbox'; }
    public function GetFolderIdForBackendId($id) { return 'client-inbox'; }
}
class RangeFind extends Find {
    public function wire($input, $output, $backend) {
        self::$decoder = new WBXMLDecoder($input);
        self::$encoder = new WBXMLEncoder($output);
        self::$backend = $backend;
        self::$deviceManager = new RangeDeviceManager();
    }
}
function responseRange($stream) {
    rewind($stream); $decoder = new WBXMLDecoder($stream); $depth = 0; $range = null;
    do {
        $element = $decoder->getElement();
        if ($element === false) throw new RuntimeException('Incomplete response');
        if ($element[EN_TYPE] === EN_TYPE_STARTTAG) {
            if ($element[EN_TAG] === SYNC_FIND_RANGE) $range = $decoder->getElementContent();
            if ($element[EN_FLAGS] & EN_FLAGS_CONTENT) $depth++;
        }
        elseif ($element[EN_TYPE] === EN_TYPE_ENDTAG) $depth--;
    } while ($depth > 0);
    return $range;
}
$cases = [
    ['initial page', '0-100', 10, 10, true, '0-9'],
    ['single result', '0-0', 1, 1, true, '0-0'],
    ['offset page', '10-19', 2, 100, true, '10-11'],
    ['last partial page', '90-99', 3, 93, true, '90-92'],
    ['empty next page', '10-109', 0, 10, true, null],
    ['no matches', '0-99', 0, 0, true, null],
    ['no provider range', '20-29', 2, 100, false, '20-21'],
    ['default initial range', null, 2, 2, false, '0-1'],
];
$results = []; $passed = true;
foreach ($cases as $case) {
    list($name, $range, $returned, $total, $includeRange, $expected) = $case;
    try {
        $provider = new RangeSearchProvider();
        $provider->range = $range; $provider->returned = $returned;
        $provider->total = $total; $provider->includeRange = $includeRange;
        $backend = new RangeBackend(); $backend->provider = $provider;
        (new ReflectionProperty(ZPush::class, 'backend'))->setValue(null, $backend);
        $output = rangeStream(); $handler = new RangeFind();
        $handler->wire(rangeInput($range), $output, $backend);
        if (!$handler->Handle(ZPush::COMMAND_FIND)) throw new RuntimeException('Request rejected');
        $actual = responseRange($output); $ok = $actual === $expected;
        $results[$name] = ['expected' => $expected, 'actual' => $actual, 'pass' => $ok];
    }
    catch (Throwable $error) {
        $ok = false; $results[$name] = ['pass' => false, 'error' => $error->getMessage()];
    }
    $passed = $passed && $ok;
}
restore_error_handler(); ob_end_clean();
echo json_encode(['result' => $passed ? 'PASS' : 'FAIL', 'cases' => $results], JSON_PRETTY_PRINT) . "\n";
exit($passed ? 0 : 1);
