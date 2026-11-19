#!/bin/sh
set -eu

# Imported TiDB databases already have the base schema. Apply only the pending
# shipping migrations, without replaying legacy MySQL trigger migrations.
php artisan migrate --force --no-interaction \
    --path=database/migrations/2026_11_19_000002_add_shipping_rates_and_coordinates.php \
    --path=database/migrations/2026_11_19_000003_support_two_level_addresses.php

exec apache2-foreground
