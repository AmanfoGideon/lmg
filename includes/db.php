<?php
/**
 * Database connection bootstrap
 * - Loads credentials from environment or includes.env (fallback to legacy values)
 * - Uses utf8mb4 and strict error reporting
 */
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function env_var(string $key, ?string $default = null): ?string {
  $v = getenv($key);
  if ($v !== false && $v !== '') return $v;
  if (!empty($_SERVER[$key])) return $_SERVER[$key];
  if (!empty($_ENV[$key])) return $_ENV[$key];
  static $loaded = false; static $vars = [];
  if (!$loaded) {
    $paths = [
      __DIR__.'/../includes.env',
      __DIR__.'/includes.env',
      dirname(__DIR__).'/.env',
      __DIR__.'/.env',
    ];
    foreach ($paths as $p) {
      if (is_file($p)) {
        $lines = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
          foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#') continue;
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
              $k = trim($parts[0]);
              $val = trim($parts[1]);
              if ($k !== '' && $val !== '') { $vars[$k] = $val; }
            }
          }
        }
      }
    }
    $loaded = true;
  }
  return isset($vars[$key]) && $vars[$key] !== '' ? $vars[$key] : $default;
}

$host = env_var('DB_HOST', 'localhost');
$user = env_var('DB_USER', 'Hepagk');
$pass = env_var('DB_PASSWORD', 'Akoben210252');
$dbname = env_var('DB_NAME', 'lmg_cm');

try {
  $conn = new mysqli($host, $user, $pass, $dbname);
  $conn->set_charset('utf8mb4');
} catch (\Throwable $e) {
  http_response_code(500);
  die('Database connection failed');
}
?>
