<?php
/** Charger les variables locales de .env sans écraser celles du serveur. */
function ma2eLoadEnvFile(string $path): void
{
    if (!is_file($path) || !is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $separator = strpos($line, '=');
        if ($separator === false) continue;

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name) || getenv($name) !== false) continue;

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
            }
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

function ma2eEnv(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : (string)$value;
}

ma2eLoadEnvFile(dirname(__DIR__) . '/.env');
