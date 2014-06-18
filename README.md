# phperror-gui

A simple but effective single-file GUI for viewing entries in the PHP error log.

### usage

Simply load the script up in your browser and it should show you the entries from the PHP log file.  It will find the error log from the ini settings, though if you want to specify the log file you can change the $error_log variable to the path of your error log file.

You can select the types of errors you want displaying, sort in different ways or filter based on the path of the file producing the error as recoded in the log.

### cache

There is a very rundementary option to cache the results.  This is set by using the $cache variable and setting it to the path of the cache file (must be a writable location). It will store the results of the file scan and then the position in the file it read up to. On subsequent reads of the file it will seek to that position and start to read the file. This works so long as you are not doing log rotation as the seek position could suddenly become much greater than the file size. So it's only recommended that you use the cache if you keep the one log file.

# License

MIT: http://acollington.mit-license.org/
