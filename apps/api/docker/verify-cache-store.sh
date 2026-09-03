#!/bin/sh

if ! php artisan cache:verify-store; then
    echo "FATAL: cache store cannot serve tenant-tagged operations" >&2
    exit 1
fi
