#!/bin/sh

cd $(dirname $0)

# tcp to http
swoole client.php 14001 test_http.bin
