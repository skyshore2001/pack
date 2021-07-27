run server:

	swoole server.php

14000: receive http, translate to tcp and send to target tcp (default=localhsot:14002)
14001: receive tcp, translate to http and send to target http (default=oliveche.com/echo.php)

run test client (http):

	cd test
	sh ./if_test.sh
	(use json data file)

run test client (tcp):

	cd test
	sh ./if_test_tcp.sh
	(use bin data file)

how to convert json package to bin package:

	nc -l 14002 > 1.bin
	sh ./if_test.sh
	(translate http to tcp and send to 14002)
