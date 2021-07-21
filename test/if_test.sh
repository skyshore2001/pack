#!/bin/sh

curl -i -H "Content-Type: application/json" --data-binary @toport.json localhost:14000
