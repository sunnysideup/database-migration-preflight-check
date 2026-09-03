#!/usr/bin/env bash
# ss-db-migration-scan.sh — flag SQL that may break on MySQL 8.x / modern MariaDB
# Usage: ./ss-db-migration-scan.sh [path]   (defaults to current dir)
set -uo pipefail
ROOT="${1:-.}"
EXCL=(--exclude-dir=vendor --exclude-dir=.git --exclude-dir=node_modules --exclude-dir=silverstripe-cache)

scan() {
    printf '\n\033[1;36m== %s ==\033[0m\n' "$1"; shift
    grep -rni "${EXCL[@]}" "$@" "$ROOT" || echo "  (none found)"
}

scan "1. SQL_CALC_FOUND_ROWS / FOUND_ROWS()" -E --include=*.php --include=*.sql \
     -e 'sql_calc_found_rows' -e 'found_rows[[:space:]]*('
scan "2. GROUP BY ... ASC|DESC" -E --include=*.php --include=*.sql \
     -e 'group[[:space:]]+by[^;)]*(asc|desc)'
scan "3. PROCEDURE ANALYSE" -E --include=*.php --include=*.sql \
     -e 'procedure[[:space:]]+analyse'
scan "4. Zero-dates (0000-00-00)" -E --include=*.php --include=*.sql --include=*.yml \
     -e '0000-00-00'
scan "5. utf8 (not utf8mb4)" -P --include=*.php --include=*.yml --include=*.sql \
     -e 'utf8(?!mb4)'
scan "6. INT display widths (in .sql dumps)" -E --include=*.sql \
     -e '\b(tinyint|smallint|mediumint|int|bigint)\([0-9]+\)'
scan "7. mysql_native_password" -E --include=*.php --include=*.yml --include=*.cnf \
     -e 'mysql_native_password'
scan "8. Reserved-word field names (eyeball these)" -E --include=*.php \
     -e "'(rank|system|rows|groups|window|over|lead|lag|recursive|lateral)'[[:space:]]*=>"
scan "9. Raw-SQL entry points (review by hand)" -E --include=*.php \
     -e 'DB::query|DB::prepared_query|->query\(|new SQLSelect|SQLSelect::create|->where\(|->addWhere\(|->sql\('

printf '\n\033[1;32mDone.\033[0m Sections 8 & 9 are review targets, not failures.\n'