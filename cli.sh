#!/usr/bin/env bash

if [ "$1" = "serve" ]; then
	cd backend/laravel || exit 1
	php artisan serve
else
	echo "Usage: ./cli.sh serve"
	exit 1
fi