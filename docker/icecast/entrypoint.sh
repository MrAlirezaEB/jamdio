#!/bin/sh
set -e

: "${ICECAST_SOURCE_PASSWORD:=hackme}"
: "${ICECAST_ADMIN_PASSWORD:=hackme}"
: "${ICECAST_MOUNT:=/stream}"

export ICECAST_SOURCE_PASSWORD ICECAST_ADMIN_PASSWORD ICECAST_MOUNT

envsubst < /etc/icecast/icecast.xml.template > /etc/icecast/icecast.xml

echo "[icecast] starting on :8000, mount ${ICECAST_MOUNT}"
exec icecast -c /etc/icecast/icecast.xml
