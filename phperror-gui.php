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

if ($error_log === null) {
    $error_log = ini_get('error_log');
}

if (empty($error_log)) {
    die('No error log was defined or could be determined from the ini settings.');
}

try {
    $log = new SplFileObject($error_log);
    $log->setFlags(SplFileObject::DROP_NEW_LINE);
} catch (RuntimeException $e) {
    die("The file '{$error_log}' cannot be opened for reading.\n");
}

if ($cache !== null && file_exists($cache)) {
    $cacheData = unserialize(file_get_contents($cache));
    extract($cacheData);
    $log->fseek($seek);
}

$prevError = new stdClass;
while (!$log->eof()) {
    if (preg_match('/stack trace:$/i', $log->current())) {
        $stackTrace = $parts = [];
        $log->next();
        while ((preg_match('!^\[(?P<time>[^\]]*)\] PHP\s+(?P<msg>\d+\. .*)$!', $log->current(), $parts)
            || preg_match('!^(?P<msg>#\d+ .*)$!', $log->current(), $parts)
            && !$log->eof())
        ) {
            $stackTrace[] = $parts['msg'];
            $log->next();
        }
        if (substr($stackTrace[0], 0, 2) == '#0') {
            $stackTrace[] = $log->current();
            $log->next();
        }
        $prevError->trace = join("\n", $stackTrace);
    }

    $more = [];
    while (!preg_match('!^\[(?P<time>[^\]]*)\] (PHP (?P<typea>.*?):|(?P<typeb>WordPress \w+ \w+))\s+(?P<msg>.*)$!', $log->current()) && !$log->eof()) {
        $more[] = $log->current();
        $log->next();
    }
    if (!empty($more)) {
        $prevError->more = join("\n", $more);
    }

    $parts = [];
    if (preg_match('!^\[(?P<time>[^\]]*)\] (PHP (?P<typea>.*?):|(?P<typeb>WordPress \w+ \w+))\s+(?P<msg>.*)$!', $log->current(), $parts)) {
        $parts['type'] = (@$parts['typea'] ?: $parts['typeb']);
        $msg = trim($parts['msg']);
        $type = strtolower(trim($parts['type']));
        $types[$type] = strtolower(preg_replace('/[^a-z]/i', '', $type));
        if (!isset($logs[$msg])) {
            $data = [
                'type'  => $type,
                'first' => date_timestamp_get(date_create($parts['time'])),
                'last'  => date_timestamp_get(date_create($parts['time'])),
                'msg'   => $msg,
                'hits'  => 1,
                'trace' => null,
                'more'  => null
            ];
            $subparts = [];
            if (preg_match('!(?<core> in (?P<path>(/|zend)[^ :]*)(?: on line |:)(?P<line>\d+))$!', $msg, $subparts)) {
                $data['path'] = $subparts['path'];
                $data['line'] = $subparts['line'];
                $data['core'] = str_replace($subparts['core'], '', $data['msg']);
                $data['code'] = '';
                try {
                    $file = new SplFileObject(str_replace('zend.view://', '', $subparts['path']));
                    $file->seek($subparts['line'] - 4);
                    $i = 7;
                    do {
                        $data['code'] .= $file->current();
                        $file->next();
                    } while (--$i && !$file->eof());
                } catch (Exception $e) {
                }
            }
            $logs[$msg] = (object)$data;
            if (!isset($typecount[$type])) {
                $typecount[$type] = 1;
            } else {
                ++$typecount[$type];
            }
        } else {
            ++$logs[$msg]->hits;
            $time = date_timestamp_get(date_create($parts['time']));
            if ($time < $logs[$msg]->first) {
                $logs[$msg]->first = $time;
            }
            if ($time > $logs[$msg]->last) {
                $logs[$msg]->last = $time;
            }
        }
        $prevError = &$logs[$msg];
    }
    $log->next();
}

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
    <script src="//code.jquery.com/jquery-2.1.3.min.js" type="text/javascript"></script>
    <style type="text/css">
        body { font-family: Arial, Helvetica, sans-serif; font-size: 80%; margin: 0; padding: 0; }
        article { width: 100%; display: block; margin: 0 0 1em 0; }
        article > div { border: 1px solid #000000; border-left-width: 10px; padding: 1em; }
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
        footer { border-top: 1px solid #ccc; padding: 1em 2em; }
        footer a {
            padding: 2em;
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
        #container { padding: 2em; }
        #typeFilter, #pathFilter, #sortOptions { border: 0; margin: 0; padding: 0; }
        #pathFilter input { width: 30em; }
        #typeFilter label { border-bottom: 4px solid #000000; margin-right: 1em; padding-bottom: 2px; }
        #nothingToShow { display: none; }
        .odd { background-color: #fcfcfc; }
        .even { background-color: #f8f8f8; }
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

<div id="container">
<?php if (!empty($logs)): ?>

    <p id="serverDetails">Error log '<?php echo htmlentities($error_log); ?>' on <?php
        echo htmlentities($host); ?> (PHP <?php echo phpversion();
        ?>, <?php echo htmlentities($_SERVER['SERVER_SOFTWARE']); ?>)</p>

    <fieldset id="typeFilter">
        <p>Filter by type:
            <?php foreach ($types as $title => $class): ?>
            <label class="<?php echo $class; ?>">
                <input type="checkbox" value="<?php echo $class; ?>" checked="checked" /> <?php
                    echo $title; ?> (<span data-total="<?php echo $typecount[$title]; ?>"><?php
                    echo $typecount[$title]; ?></span>)
            </label>
            <?php endforeach; ?>
        </p>
    </fieldset>

    <fieldset id="pathFilter">
        <p><label>Filter by path: <input type="text" value="" placeholder="Just start typing..." /></label></p>
    </fieldset>

    <fieldset id="sortOptions">
        <p>Sort by: <a href="?type=last&amp;order=asc">last seen (<span>asc</span>)</a>, <a href="?type=hits&amp;order=desc">hits (<span>desc</span>)</a>, <a href="?type=type&amp;order=asc">type (<span>a-z</span>)</a></p>
    </fieldset>

    <p id="entryCount"><?php echo $total; ?> distinct entr<?php echo($total == 1 ? 'y' : 'ies'); ?></p>

    <section>
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

    <p id="nothingToShow">Nothing to show with your selected filtering.</p>
<?php else: ?>
    <p>There are currently no PHP error log entries available.</p>
<?php endif; ?>
</div>

<footer>
    <a href="https://github.com/amnuts/phperror-gui" target="_blank">https://github.com/amnuts/phperror-gui</a>
</footer>

<script type="text/javascript">
function parseQueryString(qs) {
    var query = (qs || '?').substr(1), map = {};
    query.replace(/([^&=]+)=?([^&]*)(?:&+|$)/g, function(match, key, value) {
        (map[key] = map[key] || value);
    });
    return map;
}

function stripe() {
    $('article:visible:odd').removeClass('even').addClass('odd');
    $('article:visible:even').removeClass('odd').addClass('even');
}

function visible() {
    var vis = $('article:visible');
    var len = vis.length;
    if (len == 0) {
        $('#nothingToShow').show();
        $('#entryCount').text('0 entries showing (<?php echo $total; ?> filtered out)');
    } else {
        $('#nothingToShow').hide();
        if (len == <?php echo $total; ?>) {
            $('#entryCount').text('<?php echo $total; ?> distinct entr<?php echo($total == 1 ? 'y' : 'ies'); ?>');
        } else {
            $('#entryCount').text(len + ' distinct entr' + (len == 1 ? 'y' : 'ies') + ' showing ('
                + (<?php echo $total; ?> - len) + ' filtered out)');
        }
    }
    $('#typeFilter label span').each(function(){
        var count = ($('#pathFilter input').val() == ''
            ? $(this).data('total')
            : $(this).data('current') + '/' + $(this).data('total')
        );
        $(this).text(count);
    });
    stripe();
}

function filterSet() {
    var typeCount = {};
    var checked = $('#typeFilter input:checkbox:checked').map(function(){
        return $(this).val();
    }).get();
    var input = $('#pathFilter input').val();
    $('article').each(function(){
        var a = $(this);
        var found = a.data('path').toLowerCase().indexOf(input.toLowerCase());
        if ((input.length && found == -1) || (jQuery.inArray(a.data('type'), checked) == -1)) {
            a.hide();
        } else {
            a.show();
        }
        if (found != -1) {
            if (typeCount.hasOwnProperty(a.data('type'))) {
                ++typeCount[a.data('type')];
            } else {
                typeCount[a.data('type')] = 1;
            }
        }
    });
    $('#typeFilter label').each(function(){
        var type = $(this).attr('class');
        if (typeCount.hasOwnProperty(type)) {
            $('span', $(this)).data('current', typeCount[type]);
        } else {
            $('span', $(this)).data('current', 0);
        }
    });
}

function sortEntries(type, order) {
    var aList = $('article');
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
    $('#typeFilter input:checkbox').on('change', function(){
        filterSet();
        visible();
    });
    $('#pathFilter input').on('keyup', function(){
        filterSet();
        visible();
    });
    $('#sortOptions a').on('click', function(){
        var qs = parseQueryString($(this).attr('href'));
        sortEntries(qs.type, qs.order);
        $(this).attr('href', '?type=' + qs.type + '&order=' + (qs.order == 'asc' ? 'desc' : 'asc'));
        if (qs.type == 'type') {
            $('span', $(this)).text((qs.order == 'asc' ? 'z-a' : 'a-z'));
        } else {
            $('span', $(this)).text((qs.order == 'asc' ? 'desc' : 'asc'));
        }
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
