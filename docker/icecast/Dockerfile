# syntax=docker/dockerfile:1
FROM alpine:3.20

RUN apk add --no-cache icecast gettext

COPY docker/icecast/icecast.xml.template /etc/icecast/icecast.xml.template
COPY docker/icecast/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p /var/log/icecast \
    && chown -R icecast:icecast /var/log/icecast

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
