# Cyberia DNS as a self-contained image, for running it under a CyberiaCompute
# lease. The production compose file keeps mounting ./src read-only; this
# bakes the same source in, because a provider runs an image by digest and
# has no checkout to mount. Nothing is installed: node's standard library only.
FROM node:22-alpine
WORKDIR /app
COPY package.json ./
COPY src ./src
USER node
EXPOSE 53/udp 53/tcp 8053/tcp
ENTRYPOINT ["node", "/app/src/server.mjs"]
