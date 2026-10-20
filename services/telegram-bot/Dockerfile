# The Cyberia Telegram bot in a container, replacing the tmux session it has
# run in. It holds TELEGRAM_BOT_TOKEN and DEPLOYER_PK, so its compute manifest
# is `trusted` tier: it runs only on a provider the DAO marked trusted (today
# that is the production host itself). Configuration is environment only —
# BOT_ENV_FILE / env_file.
#
# DB_PATH must be set: by default the bot writes into Laravel's own SQLite
# file (backend/laravel/database/database.sqlite), and that coupling is why
# this service moves last, together with the site. Mount that file at /data
# and point DB_PATH at it; do not let it default to a fresh empty database.
FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /app
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY bot ./bot
RUN useradd --system --uid 10001 bot && mkdir /data && chown bot /data
USER bot
VOLUME ["/data"]
CMD ["python", "-m", "bot"]
