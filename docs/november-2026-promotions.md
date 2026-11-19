# November 2026 campaigns

Deploy the API before the frontend. On the configured database, run:

```sh
php artisan migrate --force
php artisan db:seed --class=November2026PromotionSeeder --force
```

The seeder creates five vouchers without overwriting existing codes, usage counters or administrator changes. Campaign configuration is in `config/promotions.php`. Vouchers run from November 1 through November 30, 2026 in Vietnam time, stored as UTC. Amounts use the existing catalog/checkout currency (USD); changing the frontend currency label does not convert database amounts.

Public campaign endpoint: `GET /api/v2/promotions/{campaign}` accepts `sale-11-11`, `featured-2026`, `best-sellers`, `tech-deals`, and `everyday-picks`. The three additional collections share the existing monthly vouchers, preserving the same usage quota across campaigns. Authenticated customers save via `POST /api/v2/user/account/vouchers/{id}/save`; account vouchers include `saved_at`. Saving is idempotent and does not reserve or consume redemption quota. Checkout remains the authority for expiry, minimum spend, per-customer and global usage limits.

Frontend pages use `/buyer/promotions/{campaign}`. Guest users can browse; saving prompts login and returns to any of the five campaigns. The Shoppe home carousel rotates every five seconds, loops, supports swiping, arrows and direct slide selection, and pauses on hover/focus, explicit pause, reduced-motion preference, hidden tabs and when outside the viewport.

Integration checks (transaction rollback): set `RUN_MYSQL_CHECKOUT_TESTS=1` and run `php artisan test --filter=PromotionMysqlTest` on a migrated local MySQL database.
