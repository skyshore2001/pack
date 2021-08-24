#!/bin/sh

cd $(dirname $0)
# http to tcp
curl -i -H "Content-Type: application/json" --data-binary @test_tcp.json localhost:14000
