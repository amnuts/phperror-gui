<?php

/**
 * PHP Error Log GUI
 *
 * A clean and effective single-file GUI for viewing entries in the PHP error
 * log, allowing for filtering by path and by type.
 *
 * @author Andrew Collington, andy@amnuts.com
 * @version 1.0.1
 * @link https://github.com/amnuts/phperror-gui
 * @license MIT, http://acollington.mit-license.org/
 */

/**
 * @var string|null Path to error log file or null to get from ini settings
 */
$error_log = null;
/**
 * @var string|null Path to log cache - must be writable - null for no cache
 */
$cache     = null;
/**
 * @var array Array of log lines
 */
$logs = [];
/**
 * @var array Array of log types
 */
$types = [];

/**
 * https://gist.github.com/amnuts/8633684
 */
function osort(&$array, $properties)
{
    if (is_string($properties)) {
        $properties = array($properties => SORT_ASC);
    }
    uasort($array, function ($a, $b) use ($properties) {
        foreach ($properties as $k => $v) {
            if (is_int($k)) {
                $k = $v;
                $v = SORT_ASC;
            }
            $collapse = function ($node, $props) {
                if (is_array($props)) {
                    foreach ($props as $prop) {
                        $node = (!isset($node->$prop)) ? null : $node->$prop;
                    }
                    return $node;
                } else {
                    return (!isset($node->$props)) ? null : $node->$props;
                }
            };
            $aProp = $collapse($a, $k);
            $bProp = $collapse($b, $k);
            if ($aProp != $bProp) {
                return ($v == SORT_ASC)
                ? strnatcasecmp($aProp, $bProp)
                : strnatcasecmp($bProp, $aProp);
            }
        }
        return 0;
    });
}

function pretty_message($message)
{
    $tpl_message = '<div style="margin: 20px auto; width: 400px; background-color: #eeeeee; padding: 20px;">
        <div>{{message}}</div>
    </div>';

    print(str_replace('{{message}}', $message, $tpl_message));
}

class ErrorLog
{
    const REGEX_ERROR_LINE = '!^\[(?P<time>[^\]]*)\] ((PHP|ojs2: )(?P<typea>.*?):|(?P<typeb>(WordPress|ojs2|\w has produced)\s{1,}\w+ \w+))\s+(?P<msg>.*)$!';
    const REGEX_TRACE_LINE = '/stack trace:$/i';
    const REGEX_TRACE_TYPE_A = '!^\[(?P<time>[^\]]*)\] PHP\s+(?P<msg>\d+\. .*)$!';
    const REGEX_TRACE_TYPE_B = '!^(?P<msg>#\d+ .*)$!';

    private $log;
    private $types = [];
    private $typecount = [];

    public function __construct(SplFileObject $log)
    {
        $this->log = $log;
    }

    public function getTypes()
    {
        return $this->types;
    }

    public function getTypeCounts()
    {
        return $this->typecount;
    }

    public function parse()
    {
        $log = $this->log;
        $logs = [];

        $prevError = new stdClass;
        while (!$log->eof()) {
            $this->captureStackTrace($log, $prevError);
            $this->captureAnyAdditionalText($log, $prevError);

            $parts = [];
            if (preg_match(self::REGEX_ERROR_LINE, $log->current(), $parts)) {
                $type = $this->getErrorType($parts);
                $msg = trim($parts['msg']);

                if (!isset($logs[$msg])) {
                    $logs[$msg] = $this->createEntry($type, $parts['time'], $msg);
                } else {
                    $this->updateEntry($logs[$msg], $parts['time']);
                }
                $prevError = &$logs[$msg];
            }
            $log->next();
        }

        return $logs;
    }

    protected function createEntry($type, $time, $msg)
    {
        $data = [
            'type'  => $type,
            'first' => date_timestamp_get(date_create($time)),
            'last'  => date_timestamp_get(date_create($time)),
            'msg'   => $msg,
            'hits'  => 1,
            'trace' => null,
            'more'  => null
        ];
        $entry = (object)$data;
        $this->captureFileDataFromMessage($entry);

        $this->increaseTypecount($type);

        return $entry;
    }

    protected function updateEntry($entry, $time)
    {
        ++$entry->hits;

        $time = date_timestamp_get(date_create($time));

        if ($time < $entry->first) {
            $entry->first = $time;
        }

        if ($time > $entry->last) {
            $entry->last = $time;
        }
    }

    protected function captureFileDataFromMessage($entry)
    {
        $subparts = [];
        if (preg_match('!(?<core> in (?P<path>(/|zend)[^ :]*)(?: on line |:)(?P<line>\d+))$!', $entry->msg, $subparts)) {
            $entry->path = $subparts['path'];
            $entry->line = $subparts['line'];
            $entry->core = str_replace($subparts['core'], '', $entry->msg);
            $entry->code = $this->getCodeSnippet($subparts['path'], $subparts['line']);
        }
    }

    protected function getCodeSnippet($path, $line)
    {
        $code = '';
        try {
            $file = new SplFileObject(str_replace('zend.view://', '', $path));
            $cursorline = $line - 4;
            $file->seek($cursorline);
            $i = 7;
            do {
                $code .= ++$cursorline . '. ' . $file->current();
                $file->next();
            } while (--$i && !$file->eof());
        } catch (Exception $e) {
        }

        return $code;
    }

    public function captureStackTrace($log, $prevError)
    {
        if (preg_match(self::REGEX_TRACE_LINE, $log->current())) {
            $stackTrace = $parts = [];
            $log->next();
            while ((preg_match(self::REGEX_TRACE_TYPE_A, $log->current(), $parts)
                || preg_match(self::REGEX_TRACE_TYPE_B, $log->current(), $parts)
                || preg_match(self::REGEX_TRACE_LINE, $log->current())
                && !$log->eof())
            ) {
                $stackTrace[] = $parts['msg'];
                $log->next();
            }
            // Not sure why this is here; it swallows up the next error message
            //if (substr($stackTrace[0], 0, 2) == '#0') {
            //    $stackTrace[] = $log->current();
            //    $log->next();
            //}
            $prevError->trace = join("\n", $stackTrace);
        }
    }

    public function captureAnyAdditionalText($log, $prevError)
    {
        $more = [];

        while (!preg_match(self::REGEX_ERROR_LINE, $log->current()) && !$log->eof()) {
            $more[] = $log->current();
            $log->next();
        }

        if (!empty($more)) {
            $prevError->more = join("\n", $more);
        }
    }

    public function getErrorType($parts)
    {
        $type = (@$parts['typea'] ?: $parts['typeb']);

        if ($parts[3] == 'ojs2: ' || $parts[6] == 'ojs2') {
            $type = 'ojs2 application';
        }

        $type = strtolower(trim($type));

        $this->addType($type);

        return $type;
    }

    public function addType($type)
    {
        $this->types[$type] = strtolower(preg_replace('/[^a-z]/i', '', $type));
    }

    public function increaseTypecount($type)
    {
        if (!isset($this->typecount[$type])) {
            $this->typecount[$type] = 1;
        } else {
            ++$this->typecount[$type];
        }
    }
}

if ($error_log === null) {
    $error_log = ini_get('error_log');
}

if (empty($error_log)) {
    pretty_message('No error log was defined or could be determined from the ini settings.');
    die();
}

try {
    $log = new SplFileObject($error_log);
    $log->setFlags(SplFileObject::DROP_NEW_LINE);
} catch (RuntimeException $e) {
    pretty_message("The file '{$error_log}' cannot be opened for reading.");
    die();
}

if ($cache !== null && file_exists($cache)) {
    $cacheData = unserialize(file_get_contents($cache));
    extract($cacheData);
    $log->fseek($seek);
}

$errorlog = new ErrorLog($log);
$logs = $errorlog->parse();
$types = $errorlog->getTypes();
$typecount = $errorlog->getTypeCounts();


if ($cache !== null) {
    $cacheData = serialize(['seek' => $log->getSize(), 'logs' => $logs, 'types' => $types, 'typecount' => $typecount]);
    file_put_contents($cache, $cacheData);
}

$log = null;

osort($logs, ['last' => SORT_DESC]);
$total = count($logs);
ksort($types);

$host = (function_exists('gethostname')
    ? gethostname()
    : (php_uname('n')
        ?: (empty($_SERVER['SERVER_NAME'])
            ? $_SERVER['HOST_NAME']
            : $_SERVER['SERVER_NAME']
        )
    )
);

?><!doctype html>
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="cleartype" content="on">
    <meta name="HandheldFriendly" content="True">
    <meta name="MobileOptimized" content="320">
    <meta name="generator" content="https://github.com/amnuts/phperror-gui" />
    <title>PHP error log on <?php echo htmlentities($host); ?></title>
    <script src="//code.jquery.com/jquery-2.2.1.min.js" type="text/javascript"></script>
    <style type="text/css">
        body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; margin: 0; padding: 0; overflow-y: scroll; background-color: #ffffff; }
        article { width: 100%; display: block; margin: 0 0 1em 0; background-color: #ffffff; }
        article > div { border-left: 1px solid #000000; border-left-width: 10px; padding: 1em; -webkit-box-shadow: 1px 1px 5px 0px rgba(0,0,0,0.45); -moz-box-shadow: 1px 1px 5px 0px rgba(0,0,0,0.45); box-shadow: 1px 1px 5px 0px rgba(0,0,0,0.45); }
        article > div > b { font-weight: bold; display: block; }
        article > div > i { display: block; }
        article > div > blockquote {
            display: none;
            background-color: #ededed;
            border: 1px solid #ababab;
            padding: 1em;
            overflow: auto;
            margin: 0;
        }
        footer { border-top: 1px solid #ccc; }
        footer a {
            display: block;
            padding: 1rem 26px;
            text-decoration: none;
            opacity: 0.7;
            background-position: 5px 50%;
            background-repeat: no-repeat;
            background-color: transparent;
            background-position: 0 50%;
            background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABIAAAAQCAYAAAAbBi9cAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAyBpVFh0WE1MOmNvbS5hZG9iZS54bXAAAAAAADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IkFkb2JlIFhNUCBDb3JlIDUuMC1jMDYwIDYxLjEzNDc3NywgMjAxMC8wMi8xMi0xNzozMjowMCAgICAgICAgIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6eG1wPSJodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvIiB4bWxuczp4bXBNTT0iaHR0cDovL25zLmFkb2JlLmNvbS94YXAvMS4wL21tLyIgeG1sbnM6c3RSZWY9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC9zVHlwZS9SZXNvdXJjZVJlZiMiIHhtcDpDcmVhdG9yVG9vbD0iQWRvYmUgUGhvdG9zaG9wIENTNSBXaW5kb3dzIiB4bXBNTTpJbnN0YW5jZUlEPSJ4bXAuaWlkOjE2MENCRkExNzVBQjExRTQ5NDBGRTUzMzQyMDVDNzFFIiB4bXBNTTpEb2N1bWVudElEPSJ4bXAuZGlkOjE2MENCRkEyNzVBQjExRTQ5NDBGRTUzMzQyMDVDNzFFIj4gPHhtcE1NOkRlcml2ZWRGcm9tIHN0UmVmOmluc3RhbmNlSUQ9InhtcC5paWQ6MTYwQ0JGOUY3NUFCMTFFNDk0MEZFNTMzNDIwNUM3MUUiIHN0UmVmOmRvY3VtZW50SUQ9InhtcC5kaWQ6MTYwQ0JGQTA3NUFCMTFFNDk0MEZFNTMzNDIwNUM3MUUiLz4gPC9yZGY6RGVzY3JpcHRpb24+IDwvcmRmOlJERj4gPC94OnhtcG1ldGE+IDw/eHBhY2tldCBlbmQ9InIiPz7HtUU1AAABN0lEQVR42qyUvWoCQRSF77hCLLKC+FOlCKTyIbYQUuhbWPkSFnZ2NpabUvANLGyz5CkkYGMlFtFAUmiSM8lZOVkWsgm58K079+fMnTusZl92BXbgDrTtZ2szd8fas/XBOzmBKaiCEFyTkL4pc9L8vgpNJJDyWtDna61EoXpO+xcFfXUVqtrf7Vx7m9Pub/EatvgHoYXD4ylztC14BBVwydvydgDPHPgNaErN3jLKIxAUmEvAXK21I18SJpXBGAxyBAaMlblOWOs1bMXFkMGeBFsi0pJNe/QNuV7563+gs8LfhrRfE6GaHLuRqfnUiKi6lJ034B44EXL0baTTJWujNGkG3kBX5uRyZuRkPl3WzDTBtzjnxxiDDq83yNxUk7GYuXM53jeLuMNavvAXkv4zrJkTaeGHAAMAIal3icPMsyQAAAAASUVORK5CYII=');
            font-size: 90%;
        }
        footer a:hover { opacity: 1; }
        .title { font-weight: bold; }
        .header { background-color: #6f7f59; color: #ffffff; padding: 12px 0; }
        .contain { margin: 0 auto; max-width: 880px; }
        .controls-wrapper { background-color: #ffffff; padding: 6px 0; border-bottom: 1px solid #d3d3d3; }
        .controls { margin: 0; display:flex; justify-content: flex-start; flex-wrap: wrap; }
        .controls fieldset { padding: 0; border: 0; margin: 0 12px 6px 0; }
        .controls .label { text-transform: uppercase; padding: 4px 0; color: #4a4a4a; }
        .controls .control { line-height: 1.1; }
        #typeFilter input { vertical-align: middle; margin: 0; margin-top: -1px; }
        #typeFilter label { display: inline-block; border-bottom: 4px solid #000000; margin-right: 6px; padding-bottom: 5px; color: #4a4a4a; }
        #pathFilter input { min-width: 100px; font-size: 100%; display: inline-block; padding: 3px; border: 1px solid #d3d3d3; line-height: 1.3; }
        #sortOptions a { padding: 4px 8px; border: 1px solid #d3d3d3; border-radius: 0; color: #4a4a4a; display: inline-block; text-decoration: none; background-color: #fff; background-image: -webkit-linear-gradient(top,rgba(0,0,0,0),rgba(0,0,0,0.02)); background-image: linear-gradient(top,rgba(0,0,0,0),rgba(0,0,0,0.02)); margin-right: -5px; }
        #sortOptions a:first-child { border-top-left-radius: 4px; border-bottom-left-radius: 4px; }
        #sortOptions a:last-child { border-top-right-radius: 4px; border-bottom-right-radius: 4px; }
        #sortOptions a:hover { background-color: #eee; }
        #sortOptions a.is-active { border-bottom-color: #3367d6; -webkit-box-shadow: inset 0 -1px 0 #3367d6; box-shadow: inset 0 -1px 0 #3367d6; }
        #sortOptions a span { display: inline-block; }
        .count-message { padding: 6px 0; color: #4a4a4a; }
        .errors-wrapper { background-color: #efefef; padding-bottom: 1rem; }
        .zero-state { padding: 3rem 0; }
        .hide { display: none; }
        .alternate { background-color: #f8f8f8; }
        .deprecated { border-color: #acacac !important; }
        .notice { border-color: #6dcff6 !important; }
        .warning { border-color: #fbaf5d !important; }
        .fatalerror { border-color: #f26c4f !important; }
        .strictstandards { border-color: #534741 !important; }
        .catchablefatalerror { border-color: #f68e56 !important; }
        .parseerror { border-color: #aa66cc !important; }
    </style>
</head>
<body>

<div id="page">

<div class="header">
    <div class="contain">
        <span class="title">PHPError GUI</span> &bull;
        <span class="server-details">
            Error log '<?php echo htmlentities($error_log); ?>'
            on <?php echo htmlentities($host); ?> (PHP <?php echo phpversion(); ?>,
            <?php echo htmlentities($_SERVER['SERVER_SOFTWARE']); ?>)
        </span>
    </div>
</div>

<?php if (!empty($logs)): ?>
<div class="controls-wrapper">
    <div class="contain">
        <div class="controls">
            <fieldset id="typeFilter">
                <div class="label">Filter by type</div>
                <div class="control">
                    <?php foreach ($types as $title => $class): ?>
                    <label class="<?php echo $class; ?>">
                        <input type="checkbox" value="<?php echo $class; ?>" checked="checked" /> <?php
                            echo $title; ?> (<span data-total="<?php echo $typecount[$title]; ?>"><?php
                            echo $typecount[$title]; ?></span>)
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset id="pathFilter">
                <div class="label">Filter by path</div>
                <div class="control">
                    <input type="text" value="" placeholder="Just start typing..." />
                </div>
            </fieldset>

            <fieldset id="sortOptions">
                <div class="label">Sort by</div>
                <div class="control">
                    <a href="?type=last&amp;order=asc" class="is-active">last seen <span>↓</span></a>
                    <a href="?type=hits&amp;order=desc">hits <span> </span></a>
                    <a href="?type=type&amp;order=asc">type <span> </span></a>
                </div>
            </fieldset>
        </div>
    </div>
</div>

<div class="errors-wrapper">
<div class="contain">
    <div id="entryCount" class="count-message"><?php echo $total; ?> distinct entr<?php echo($total == 1 ? 'y' : 'ies'); ?></div>

    <section id="errorList">
    <?php foreach ($logs as $log): ?>
        <article class="<?php echo $types[$log->type]; ?>"
                data-path="<?php if (!empty($log->path)) echo htmlentities($log->path); ?>"
                data-line="<?php if (!empty($log->line)) echo $log->line; ?>"
                data-type="<?php echo $types[$log->type]; ?>"
                data-hits="<?php echo $log->hits; ?>"
                data-last="<?php echo $log->last; ?>">
            <div class="<?php echo $types[$log->type]; ?>">
                <i><?php echo htmlentities($log->type); ?></i> <b><?php echo htmlentities((empty($log->core) ? $log->msg : $log->core)); ?></b><br />
                <?php if (!empty($log->more)): ?>
                    <p><i><?php echo nl2br(htmlentities($log->more)); ?></i></p>
                <?php endif; ?>
                <p>
                    <?php if (!empty($log->path)): ?>
                        <?php echo htmlentities($log->path); ?>, line <?php echo $log->line; ?><br />
                    <?php endif; ?>
                    last seen <?php echo date_format(date_create("@{$log->last}"), 'Y-m-d G:ia'); ?>, <?php echo $log->hits; ?> hit<?php echo($log->hits == 1 ? '' : 's'); ?><br />
                </p>
                <?php if (!empty($log->trace)): ?>
                    <?php $uid = uniqid('tbq'); ?>
                    <p><a href="#" class="traceblock" data-for="<?php echo $uid; ?>">Show stack trace</a></p>
                    <blockquote id="<?php echo $uid; ?>"><?php echo highlight_string($log->trace, true); ?></blockquote>
                <?php endif; ?>
                <?php if (!empty($log->code)): ?>
                    <?php $uid = uniqid('cbq'); ?>
                    <p><a href="#" class="codeblock" data-for="<?php echo $uid; ?>">Show code snippet</a></p>
                    <blockquote id="<?php echo $uid; ?>"><?php echo highlight_string($log->code, true); ?></blockquote>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
    </section>

    <p id="nothingToShow" class="zero-state hide">Nothing to show with your selected filtering.</p>
</div>
</div>
<?php else: ?>
    <div class="contain">
        <p class="zero-state">There are currently no PHP error log entries available.</p>
    </div>
<?php endif; ?>
</div><!-- #page -->

<footer>
    <div class="contain">
        <a href="https://github.com/amnuts/phperror-gui" target="_blank">https://github.com/amnuts/phperror-gui</a>
    </div>
</footer>

<script type="text/javascript">
    var debounce = function(func, wait, immediate) {
        var timeout;
        wait = wait || 250;
        return function() {
            var context = this, args = arguments;
            var later = function() {
                timeout = null;
                if (!immediate) {
                    func.apply(context, args);
                }
            };
            var callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) {
                func.apply(context, args);
            }
        };
    };

    function parseQueryString(qs) {
        var query = (qs || '?').substr(1), map = {};
        query.replace(/([^&=]+)=?([^&]*)(?:&+|$)/g, function(match, key, value) {
            (map[key] = map[key] || value);
        });
        return map;
    }

    function stripe() {
        var errors = $('#errorList').find('article');
        errors.removeClass('alternate');
        errors.filter(':not(.hide):odd').addClass('alternate');
    }

    function visible() {
        var vis = $('#errorList').find('article').filter(':not(.hide)');
        var len = vis.length;
        if (len == 0) {
            $('#nothingToShow').removeClass('hide');
            $('#entryCount').text('0 entries showing (<?php echo $total; ?> filtered out)');
        } else {
            $('#nothingToShow').addClass('hide');
            if (len == <?php echo $total; ?>) {
                $('#entryCount').text('<?php echo $total; ?> distinct entr<?php echo($total == 1 ? 'y' : 'ies'); ?>');
            } else {
                $('#entryCount').text(len + ' distinct entr' + (len == 1 ? 'y' : 'ies') + ' showing ('
                    + (<?php echo $total; ?> - len) + ' filtered out)');
            }
        }
        $('#typeFilter').find('label span').each(function(){
            var count = ($('#pathFilter').find('input').val() == ''
                ? $(this).data('total')
                : $(this).data('current') + '/' + $(this).data('total')
            );
            $(this).text(count);
        });
        stripe();
    }

    function filterSet() {
        var typeCount = {};
        var checked = $('#typeFilter').find('input:checkbox:checked').map(function(){
            return $(this).val();
        }).get();
        var input = $('#pathFilter').find('input').val();
        $('article').each(function(){
            var a = $(this);
            var found = a.data('path').toLowerCase().indexOf(input.toLowerCase());
            if ((input.length && found == -1) || (jQuery.inArray(a.data('type'), checked) == -1)) {
                a.addClass('hide');
            } else {
                a.removeClass('hide');
            }
            if (found != -1) {
                if (typeCount.hasOwnProperty(a.data('type'))) {
                    ++typeCount[a.data('type')];
                } else {
                    typeCount[a.data('type')] = 1;
                }
            }
        });
        $('#typeFilter').find('label').each(function(){
            var type = $(this).attr('class');
            if (typeCount.hasOwnProperty(type)) {
                $('span', $(this)).data('current', typeCount[type]);
            } else {
                $('span', $(this)).data('current', 0);
            }
        });
    }

    function sortEntries(type, order) {
        var aList = $('#errorList').find('article');
        aList.sort(function(a, b){
            if (!isNaN($(a).data(type))) {
                var entryA = parseInt($(a).data(type));
                var entryB = parseInt($(b).data(type));
            } else {
                var entryA = $(a).data(type);
                var entryB = $(b).data(type);
            }
            if (order == 'asc') {
                return (entryA < entryB) ? -1 : (entryA > entryB) ? 1 : 0;
            }
            return  (entryB < entryA) ? -1 : (entryB > entryA) ? 1 : 0;
        });
        $('section').html(aList);
    }

    $(function(){
        $('#typeFilter').find('input:checkbox').on('change', function(){
            filterSet();
            visible();
        });
        $('#pathFilter').find('input').on('keyup', debounce(function(){
            filterSet();
            visible();
        }));
        $('#sortOptions').find('a').on('click', function(){
            var qs = parseQueryString($(this).attr('href'));
            sortEntries(qs.type, qs.order);

            $('#sortOptions a').removeClass('is-active');
            $(this).addClass('is-active');

            $('#sortOptions a span').text(' ');
            $(this).attr('href', '?type=' + qs.type + '&order=' + (qs.order == 'asc' ? 'desc' : 'asc'));
            $('span', $(this)).text((qs.order == 'asc' ? '↑' : '↓'));
            return false;
        });
        $(document).on('click', 'a.codeblock, a.traceblock', function(e){
            $('#' + $(this).data('for')).toggle();
            return false;
        });
        stripe();
    });
</script>

</body>
</html>
