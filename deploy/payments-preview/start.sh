#!/bin/sh
set -eu
cd /app
[ "${PAYMENTS_PREVIEW:-}" = "true" ] && [ "${DB_DATABASE:-}" = "bookboll_preview" ] || { echo 'Preview database guard refused startup'; exit 1; }
export MYSQL_PWD="$DB_PASSWORD"
db() { mariadb --protocol=TCP --host="$DB_HOST" --port="${DB_PORT:-3306}" --user="$DB_USERNAME" --database="$DB_DATABASE" --batch --skip-column-names "$@"; }
attempt=0
until db -e 'SELECT 1' >/dev/null 2>&1; do
    attempt=$((attempt+1)); [ "$attempt" -lt 60 ] || { echo 'Private database unavailable'; exit 1; }
    sleep 2
done
marker=$(db -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='preview_import_marker'")
if [ "$marker" = "0" ]; then
    tables=$(db -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')
    [ "$tables" = "0" ] || { echo 'Database has data but no completed-import marker; refusing to overwrite it'; exit 1; }
    echo 'Importing the isolated repository snapshot...'
    db < /opt/bookboll-snapshot.sql
    db -e 'CREATE TABLE preview_import_marker (completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP); INSERT INTO preview_import_marker VALUES (CURRENT_TIMESTAMP)'
fi
unset MYSQL_PWD
php artisan package:discover --ansi
php artisan migrate --force
php artisan payments:prepare-preview
mkdir -p storage/app/public storage/app/private
php artisan storage:link --force
php artisan config:cache
php artisan bookings:reconcile-creators --apply
php artisan payments:reconcile-owned-history --apply
php artisan payments:reconcile-retained-deposits --apply
php artisan workspace:sync-media
php artisan payments:warm-report
php artisan payments:release-check --allow-workspace
chown -R www-data:www-data storage bootstrap/cache
php-fpm -D
exec nginx -g 'daemon off;'
