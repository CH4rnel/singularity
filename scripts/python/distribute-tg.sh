#!/bin/bash
cd /root/singularity/scripts/python
source /root/singularity/scripts/python/bin/activate
export DB_PATH="/root/singularity/backend/laravel/database/database.sqlite"
export DEPLOYER_PK=$(grep DEPLOYER_PK /root/singularity/scripts/python/.env | cut -d= -f2)
python distribute_tg.py