run server:

	swoole server.php

8081: http
8082: tcp

run test client (http):

	cd test
	sh ./if_test.sh
	(use data from 1.json)

run test client (tcp):

	cd test
	sh ./if_test_tcp.sh
	(use data from 1.data)

