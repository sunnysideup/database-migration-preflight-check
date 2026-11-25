#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * fix-collation.php
 *
 * Converts a Silverstripe 6 MySQL/MariaDB database (database default, every
 * table and every text column) to the charset/collation Silverstripe 6 expects:
 *
 *     utf8mb4 / utf8mb4_unicode_ci
 *
 * (see MySQLDatabase::$charset / $collation in silverstripe/framework 6).
 *
 * Connection details are read from the project's .env file using the same
 * SS_DATABASE_* variables Silverstripe uses (including prefix/suffix, port,
 * socket and SSL settings). Real environment variables take precedence over
 * the .env file, exactly like Silverstripe.
 *
 * Usage:
 *   php fix-collation.php [options]
 *
 * Options:
 *   --env=PATH          Path to the .env file (default: search cwd and parents)
 *   --database=NAME     Override the database name (prefix/suffix NOT applied)
 *   --charset=NAME      Target charset   (default: utf8mb4)
 *   --collation=NAME    Target collation (default: utf8mb4_unicode_ci)
 *   --innodb            Also convert non-InnoDB tables (e.g. MyISAM) to InnoDB
 *   --dry-run           Only report what would change, change nothing
 *   -y, --yes           Don't ask for confirmation
 *   -h, --help          Show this help
 *
 * BACK UP FIRST:  mysqldump --single-transaction --routines DBNAME > backup.sql
 */

const DEFAULT_CHARSET   = 'utf8mb4';
const DEFAULT_COLLATION = 'utf8mb4_unicode_ci';

// ---------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------

final class Out
{
    private static ?bool $colour = null;

    private static function c(string $code, string $text): string
    {
        self::$colour ??= function_exists('posix_isatty') && posix_isatty(STDOUT) && getenv('NO_COLOR') === false;
        return self::$colour ? "\033[{$code}m{$text}\033[0m" : $text;
    }

    public static function line(string $text = ''): void { fwrite(STDOUT, $text . PHP_EOL); }
    public static function info(string $text): void     { self::line(self::c('36', $text)); }
    public static function ok(string $text): void       { self::line(self::c('32', $text)); }
    public static function warn(string $text): void     { self::line(self::c('33', $text)); }
    public static function head(string $text): void     { self::line(PHP_EOL . self::c('1', $text)); }

    public static function error(string $text): void
    {
        fwrite(STDERR, self::c('31', $text) . PHP_EOL);
    }

    public static function fail(string $text, int $code = 1): never
    {
        self::error($text);
        exit($code);
    }
}

// ---------------------------------------------------------------------------
// .env handling
// ---------------------------------------------------------------------------

function findEnvFile(?string $explicit): string
{
    if ($explicit !== null) {
        $path = realpath($explicit);
        if ($path === false || !is_file($path)) {
            Out::fail("The .env file '{$explicit}' does not exist.");
        }
        return $path;
    }

    foreach (array_unique([getcwd(), __DIR__]) as $start) {
        $dir = $start;
        while ($dir !== '' && $dir !== false) {
            if (is_file($dir . '/.env')) {
                return $dir . '/.env';
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }

    Out::fail('Could not find a .env file. Use --env=/path/to/.env');
}

/**
 * Load the .env file. If the project's vendor/autoload.php is available we use
 * Silverstripe's own EnvironmentLoader (m1/env parser) so the result is
 * identical to what the site sees. Otherwise a compatible fallback parser is used.
 *
 * @return array<string, string>
 */
function loadEnv(string $envFile): array
{
    $autoload = dirname($envFile) . '/vendor/autoload.php';

    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists(\SilverStripe\Core\EnvironmentLoader::class)) {
            (new \SilverStripe\Core\EnvironmentLoader())->loadFile($envFile);
            Out::info('Loaded .env with Silverstripe\'s EnvironmentLoader');

            $vars = [];
            foreach (ssDatabaseKeys() as $key) {
                $value = \SilverStripe\Core\Environment::getEnv($key);
                if ($value !== false && $value !== null) {
                    $vars[$key] = (string) $value;
                }
            }
            return $vars;
        }
    }

    Out::info('Loaded .env with built-in parser (vendor/autoload.php not found)');
    $vars = parseEnvFile($envFile);

    // Real environment variables win, as they do in Silverstripe.
    foreach (ssDatabaseKeys() as $key) {
        $real = getenv($key);
        if ($real !== false) {
            $vars[$key] = $real;
        }
    }
    return $vars;
}

/** @return list<string> */
function ssDatabaseKeys(): array
{
    return [
        'SS_DATABASE_CLASS', 'SS_DATABASE_SERVER', 'SS_DATABASE_PORT', 'SS_DATABASE_SOCKET',
        'SS_DATABASE_USERNAME', 'SS_DATABASE_PASSWORD', 'SS_DATABASE_NAME',
        'SS_DATABASE_PREFIX', 'SS_DATABASE_SUFFIX', 'SS_DATABASE_CHOOSE_NAME',
        'SS_DATABASE_SSL_KEY', 'SS_DATABASE_SSL_CERT', 'SS_DATABASE_SSL_CA', 'SS_DATABASE_SSL_CIPHER',
    ];
}

/**
 * Minimal dotenv parser: comments, `export`, single quotes (literal),
 * double quotes (escapes + ${VAR}), unquoted values with inline comments.
 *
 * @return array<string, string>
 */
function parseEnvFile(string $file): array
{
    $vars  = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

    $interpolate = static function (string $value) use (&$vars): string {
        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static fn(array $m): string => $vars[$m[1]] ?? (getenv($m[1]) ?: ''),
            $value
        ) ?? $value;
    };

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }
        [, $key, $raw] = $m;

        if ($raw !== '' && $raw[0] === "'") {
            $value = preg_match("/^'(.*?)'/", $raw, $q) ? $q[1] : substr($raw, 1);
        } elseif ($raw !== '' && $raw[0] === '"') {
            $value = preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $raw, $q) ? $q[1] : substr($raw, 1);
            $value = strtr($value, ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\', '\\$' => '$']);
            $value = $interpolate($value);
        } else {
            $value = trim((string) preg_replace('/\s+#.*$/', '', $raw));
            $value = $interpolate($value);
        }

        $vars[$key] = $value;
    }

    return $vars;
}

// ---------------------------------------------------------------------------
// Database helpers
// ---------------------------------------------------------------------------

function qi(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * @param array<string, string> $env
 * @return array{0: PDO, 1: string}
 */
function connect(array $env, ?string $dbOverride): array
{
    $class = $env['SS_DATABASE_CLASS'] ?? 'MySQLDatabase';
    if (!str_contains(strtolower($class), 'mysql')) {
        Out::fail("SS_DATABASE_CLASS is '{$class}'. This script only supports MySQL/MariaDB.");
    }

    if ($dbOverride !== null) {
        $dbName = $dbOverride;
    } elseif (!empty($env['SS_DATABASE_NAME'])) {
        $dbName = ($env['SS_DATABASE_PREFIX'] ?? '') . $env['SS_DATABASE_NAME'] . ($env['SS_DATABASE_SUFFIX'] ?? '');
    } else {
        Out::fail('SS_DATABASE_NAME is not set (SS_DATABASE_CHOOSE_NAME is not supported here). Use --database=NAME');
    }

    $server = $env['SS_DATABASE_SERVER'] ?? 'localhost';
    $port   = $env['SS_DATABASE_PORT'] ?? '';
    $socket = $env['SS_DATABASE_SOCKET'] ?? '';

    // Allow "host:port" in SS_DATABASE_SERVER
    if ($port === '' && preg_match('/^([^:\[\]]+):(\d+)$/', $server, $m)) {
        [, $server, $port] = $m;
    }

    $dsn = $socket !== ''
        ? "mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4"
        : "mysql:host={$server};" . ($port !== '' ? "port={$port};" : '') . "dbname={$dbName};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    // SSL (works on PHP 8.3 and 8.4+, where the constants moved to Pdo\Mysql)
    $ssl = [
        'SS_DATABASE_SSL_KEY'    => 'ATTR_SSL_KEY',
        'SS_DATABASE_SSL_CERT'   => 'ATTR_SSL_CERT',
        'SS_DATABASE_SSL_CA'     => 'ATTR_SSL_CA',
        'SS_DATABASE_SSL_CIPHER' => 'ATTR_SSL_CIPHER',
    ];
    foreach ($ssl as $envKey => $attr) {
        if (!empty($env[$envKey])) {
            $const = defined("Pdo\\Mysql::{$attr}") ? "Pdo\\Mysql::{$attr}" : "PDO::MYSQL_{$attr}";
            $options[constant($const)] = $env[$envKey];
        }
    }

    try {
        $pdo = new PDO($dsn, $env['SS_DATABASE_USERNAME'] ?? '', $env['SS_DATABASE_PASSWORD'] ?? '', $options);
    } catch (PDOException $e) {
        Out::fail('Could not connect: ' . $e->getMessage());
    }

    return [$pdo, $dbName];
}

/**
 * Collect every base table and decide what needs to change.
 *
 * @return list<array{name: string, collation: ?string, engine: ?string, row_format: ?string, bad_columns: int}>
 */
function inspectTables(PDO $pdo, string $db, string $charset, string $collation): array
{
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, TABLE_COLLATION, ENGINE, ROW_FORMAT
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
          ORDER BY TABLE_NAME"
    );
    $stmt->execute([$db]);
    $tables = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, COUNT(*) AS n
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ?
            AND COLLATION_NAME IS NOT NULL
            AND (COLLATION_NAME <> ? OR CHARACTER_SET_NAME <> ?)
          GROUP BY TABLE_NAME"
    );
    $stmt->execute([$db, $collation, $charset]);
    $badColumns = array_column($stmt->fetchAll(), 'n', 'TABLE_NAME');

    return array_map(static fn(array $t): array => [
        'name'        => $t['TABLE_NAME'],
        'collation'   => $t['TABLE_COLLATION'],
        'engine'      => $t['ENGINE'],
        'row_format'  => $t['ROW_FORMAT'],
        'bad_columns' => (int) ($badColumns[$t['TABLE_NAME']] ?? 0),
    ], $tables);
}

/**
 * Build the ALTER TABLE statement for a table, or null if nothing to do.
 *
 * @param array{name: string, collation: ?string, engine: ?string, row_format: ?string, bad_columns: int} $t
 */
function planTable(array $t, string $charset, string $collation, bool $toInnodb): ?string
{
    $isInnodb    = strcasecmp((string) $t['engine'], 'InnoDB') === 0;
    $needEngine  = $toInnodb && !$isInnodb;
    $needConvert = $t['collation'] !== $collation || $t['bad_columns'] > 0;

    if (!$needConvert && !$needEngine) {
        return null;
    }

    $parts = [];
    if ($needEngine) {
        $parts[] = 'ENGINE=InnoDB';
    }
    // utf8mb4 indexes on VARCHAR(255) need a DYNAMIC (or COMPRESSED) row format
    // on InnoDB; COMPACT/REDUNDANT limit index prefixes to 767 bytes.
    if (($isInnodb || $needEngine) && in_array(strtolower((string) $t['row_format']), ['compact', 'redundant'], true)) {
        $parts[] = 'ROW_FORMAT=DYNAMIC';
    }
    $parts[] = "CONVERT TO CHARACTER SET {$charset} COLLATE {$collation}";

    return 'ALTER TABLE ' . qi($t['name']) . ' ' . implode(', ', $parts);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$opts = getopt('yh', ['env:', 'database:', 'charset:', 'collation:', 'innodb', 'dry-run', 'yes', 'help']);

if (isset($opts['h']) || isset($opts['help'])) {
    $doc = (string) file_get_contents(__FILE__);
    preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
    Out::line(preg_replace('/^\s*\* ?/m', '', $m[1] ?? ''));
    exit(0);
}

$charset   = (string) ($opts['charset'] ?? DEFAULT_CHARSET);
$collation = (string) ($opts['collation'] ?? DEFAULT_COLLATION);
$dryRun    = isset($opts['dry-run']);
$assumeYes = isset($opts['y']) || isset($opts['yes']);
$toInnodb  = isset($opts['innodb']);

foreach (['charset' => $charset, 'collation' => $collation] as $label => $value) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        Out::fail("Invalid {$label} '{$value}'.");
    }
}

if (!extension_loaded('pdo_mysql')) {
    Out::fail('The pdo_mysql extension is required (Ubuntu: sudo apt install php8.3-mysql).');
}

$envFile = findEnvFile(isset($opts['env']) ? (string) $opts['env'] : null);
Out::info("Using {$envFile}");
$env = loadEnv($envFile);

[$pdo, $db] = connect($env, isset($opts['database']) ? (string) $opts['database'] : null);

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
Out::info("Connected to {$db} on " . (str_contains(strtolower($version), 'mariadb') ? 'MariaDB ' : 'MySQL ') . $version);

// Make sure the target collation exists and belongs to the target charset.
$stmt = $pdo->prepare('SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ?');
$stmt->execute([$collation]);
$collationCharset = $stmt->fetchColumn();
if ($collationCharset === false) {
    Out::fail("The server does not support collation '{$collation}'.");
}
if ($collationCharset !== $charset) {
    Out::fail("Collation '{$collation}' belongs to charset '{$collationCharset}', not '{$charset}'.");
}

Out::line("Target: {$charset} / {$collation}");

// --- Inspect -----------------------------------------------------------------

$stmt = $pdo->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$stmt->execute([$db]);
$schema   = $stmt->fetch() ?: Out::fail("Database '{$db}' not found.");
$dbNeedsFix = $schema['DEFAULT_CHARACTER_SET_NAME'] !== $charset || $schema['DEFAULT_COLLATION_NAME'] !== $collation;

Out::head('Database');
Out::line(sprintf(
    '  %s: %s / %s %s',
    $db,
    $schema['DEFAULT_CHARACTER_SET_NAME'],
    $schema['DEFAULT_COLLATION_NAME'],
    $dbNeedsFix ? '-> needs fixing' : '(ok)'
));

$tables = inspectTables($pdo, $db, $charset, $collation);
$plan   = [];
$nonInnodb = [];

foreach ($tables as $t) {
    if (strcasecmp((string) $t['engine'], 'InnoDB') !== 0) {
        $nonInnodb[] = "{$t['name']} ({$t['engine']})";
    }
    if (($sql = planTable($t, $charset, $collation, $toInnodb)) !== null) {
        $plan[$t['name']] = ['sql' => $sql, 'table' => $t];
    }
}

Out::head(sprintf('Tables: %d total, %d need changes', count($tables), count($plan)));
foreach ($plan as $name => $p) {
    $t = $p['table'];
    Out::line(sprintf(
        '  %-45s %-22s %3d column(s)%s',
        $name,
        $t['collation'] ?? '-',
        $t['bad_columns'],
        str_contains($p['sql'], 'ROW_FORMAT') ? '  +row_format' : ''
    ));
}

if ($nonInnodb !== [] && !$toInnodb) {
    Out::warn(PHP_EOL . 'Non-InnoDB tables found (utf8mb4 indexes may exceed MyISAM\'s 1000-byte key limit):');
    Out::warn('  ' . implode(', ', $nonInnodb));
    Out::warn('  Re-run with --innodb to convert them to InnoDB as well.');
}

if (!$dbNeedsFix && $plan === []) {
    Out::ok(PHP_EOL . 'Nothing to do: database and all tables already use ' . "{$charset} / {$collation}.");
    exit(0);
}

if ($dryRun) {
    Out::head('SQL that would run (dry run)');
    if ($dbNeedsFix) {
        Out::line('  ALTER DATABASE ' . qi($db) . " CHARACTER SET {$charset} COLLATE {$collation};");
    }
    foreach ($plan as $p) {
        Out::line("  {$p['sql']};");
    }
    exit(0);
}

// --- Confirm -----------------------------------------------------------------

if (!$assumeYes) {
    if (!stream_isatty(STDIN)) {
        Out::fail('Not running interactively. Re-run with --yes to apply, or --dry-run to preview.');
    }
    Out::warn(PHP_EOL . 'This will ALTER your database. Make sure you have a backup:');
    Out::warn("  mysqldump --single-transaction --routines {$db} > {$db}-" . date('Ymd-His') . '.sql');
    fwrite(STDOUT, PHP_EOL . 'Continue? [y/N] ');
    if (strtolower(trim((string) fgets(STDIN))) !== 'y') {
        Out::line('Aborted.');
        exit(1);
    }
}

// --- Apply -------------------------------------------------------------------

// Old Silverstripe databases often contain zero dates ('0000-00-00'); strict
// modes with NO_ZERO_DATE make ALTER TABLE fail on those. Relax only those flags.
$sqlMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$relaxed = implode(',', array_diff(explode(',', $sqlMode), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE']));
$pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($relaxed));
$pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');

$failures = [];

if ($dbNeedsFix) {
    Out::head('Fixing database default');
    try {
        $pdo->exec('ALTER DATABASE ' . qi($db) . " CHARACTER SET {$charset} COLLATE {$collation}");
        Out::ok("  {$db}: done");
    } catch (PDOException $e) {
        $failures[$db] = $e->getMessage();
        Out::error("  {$db}: " . $e->getMessage());
    }
}

if ($plan !== []) {
    Out::head('Converting tables');
    $i     = 0;
    $total = count($plan);
    foreach ($plan as $name => $p) {
        $i++;
        $start = microtime(true);
        fwrite(STDOUT, sprintf('  [%d/%d] %-45s ', $i, $total, $name));
        try {
            $pdo->exec($p['sql']);
            Out::ok(sprintf('ok (%.2fs)', microtime(true) - $start));
        } catch (PDOException $e) {
            $failures[$name] = $e->getMessage();
            Out::error('FAILED: ' . $e->getMessage());
        }
    }
}

$pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 1');

// --- Verify ------------------------------------------------------------------

Out::head('Verifying');
$remaining = array_filter(
    inspectTables($pdo, $db, $charset, $collation),
    static fn(array $t): bool => $t['collation'] !== $collation || $t['bad_columns'] > 0
);
$stmt = $pdo->prepare('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$stmt->execute([$db]);
$dbCollation = $stmt->fetchColumn();

if ($dbCollation === $collation && $remaining === [] && $failures === []) {
    Out::ok("  All good: database and all tables are {$charset} / {$collation}.");
    Out::line('  Next: run  vendor/bin/sake db:build --flush  to let Silverstripe check the schema.');
    exit(0);
}

if ($dbCollation !== $collation) {
    Out::error("  Database default collation is still {$dbCollation}.");
}
foreach ($remaining as $t) {
    Out::error(sprintf('  %s: table %s, %d column(s) still differ', $t['name'], $t['collation'] ?? '-', $t['bad_columns']));
}
exit(1);
