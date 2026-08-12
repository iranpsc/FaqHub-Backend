#!/bin/sh
set -e

# Probe PHP-FPM via FastCGI status (requires pm.status_path=/status)
SCRIPT_NAME=/status
SCRIPT_FILENAME=/status
REQUEST_METHOD=GET

cgi-fcgi -bind -connect 127.0.0.1:9000 >/dev/null 2>&1
