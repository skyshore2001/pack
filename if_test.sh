#!/bin/sh

curl -v -H "Content-Type: application/json" --data-binary @1.json localhost:8081
