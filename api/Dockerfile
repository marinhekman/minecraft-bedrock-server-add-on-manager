FROM alpine

RUN apk add --no-cache socat

CMD ["sh", "-c", "socat -v TCP-LISTEN:2375,reuseaddr,fork UNIX-CLIENT:/var/run/docker.sock"]

