run server:

	swoole server.php

14000: http->tcp
14001: tcp->http

run test client (http):

	cd test
	sh ./if_test.sh
	(use json data file)

run test client (tcp):

	cd test
	sh ./if_test_tcp.sh
	(use bin data file)

