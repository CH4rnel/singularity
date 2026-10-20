# blog.cyberia.church as an image: built once by Jekyll, served by nginx.
FROM ruby:3.3-slim AS build
RUN apt-get update && apt-get install -y --no-install-recommends build-essential git && rm -rf /var/lib/apt/lists/*
WORKDIR /site
COPY Gemfile Gemfile.lock ./
RUN bundle install
COPY . .
RUN JEKYLL_ENV=production bundle exec jekyll build --destination /out

FROM nginx:1.27-alpine
COPY --from=build /out /usr/share/nginx/html
EXPOSE 80
