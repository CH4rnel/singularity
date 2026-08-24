#!/bin/bash
cd /root/singularity/scripts/python
source /root/singularity/scripts/python/bin/activate
export DB_PATH="/root/singularity/backend/laravel/database/database.sqlite"
export DEPLOYER_PK=$(grep DEPLOYER_PK /root/singularity/scripts/python/.env | cut -d= -f2)

# Cron opens distribute_chats.log before this wrapper starts. copytruncate in
# the shared policy keeps that file descriptor valid while bounding the file;
# a rotation failure must never stop rewards from being distributed.
if [ -x /usr/sbin/logrotate ]; then
    /usr/sbin/logrotate \
        --state /var/lib/logrotate/cyberia-crons.status \
        /root/singularity/scripts/ops/logrotate-cyberia-crons || true
fi

python distribute_chat_tokens.py
