#!/bin/sh
# Runs once, on first start of an empty database volume.
set -e
pg_restore --no-owner --no-privileges -U "$POSTGRES_USER" -d "$POSTGRES_DB" /dump/kingdata.sql || true
psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT count(*) AS ratanakosin_rows FROM public.ratanakosin;"
