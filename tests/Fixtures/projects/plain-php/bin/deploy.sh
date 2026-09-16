#!/bin/sh
set -e
rsync -a --exclude var ./ "$1"
