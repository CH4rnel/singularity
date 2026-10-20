# The landing pages as an image: plain static files behind nginx, so any
# CyberiaCompute provider can serve them. Nothing here holds a secret.
FROM nginx:1.27-alpine
COPY . /usr/share/nginx/html
RUN rm -f /usr/share/nginx/html/Dockerfile /usr/share/nginx/html/.dockerignore
EXPOSE 80
