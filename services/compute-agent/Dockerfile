# The compute agent itself. It drives the host's docker daemon, so it needs
# the socket — see docker-compose.yml — which is root on that host: run the
# agent only on a machine whose every container you are willing to have it
# manage. It never runs a container without a CyberiaCompute lease label.
FROM node:22-alpine
RUN apk add --no-cache docker-cli
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev
COPY src ./src
COPY catalog.json ./
ENTRYPOINT ["node", "/app/src/agent.mjs"]
