#!/bin/sh

curl -i -H "Content-Type: application/json" --data-binary @arrive.json localhost:14000
