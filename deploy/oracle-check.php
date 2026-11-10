<?php

declare(strict_types=1);

/*
 * oracle-check.php: the plain-language Oracle connection check for the release log.
 *
 * Framework-free, no Composer dependency, and it never fails the release: scripts/release.sh runs it with
 * `|| true`, so a finding prints in the deployment log and the release goes on. When the app does not use
 * Oracle (no ORACLE_* settings) it prints nothing and exits 0.
 *
 * Settings (environment variables):
 *   ORACLE_USERNAME, ORACLE_PASSWORD
 *   ORACLE_TNS                   Easy Connect host:port/service, or a full (DESCRIPTION=...) descriptor
 *   ORACLE_HOST, ORACLE_PORT, ORACLE_SERVICE_NAME   the same address as three parts
 *
 * The check order is: settings present, host resolves, the port answers within 3 seconds (a closed or
 * silently dropped port must not hang the release for the 60 second default_socket_timeout), the oci8 driver
 * is loaded, and the login works. It stops at the first failure, so a bad port never costs a login attempt.
 * Only setting names are ever printed, never values.
 *
 * The first Oracle connection is where new developers get stuck; this file turns the raw errors into
 * sentences that say what is wrong and what to click.
 */

final class SquireOracleCheck
{
    /** Seconds the port check waits before giving up; far below PHP's 60 second default_socket_timeout. */
    private const PORT_TIMEOUT = 3.0;

    /**
     * @param array<string, string> $env       the settings; the CLI passes getenv()
     * @param callable|null         $connector replaces the real driver connect in tests;
     *                                         fn(string $user, string $password, string $connectString):
     *                                         array{ok: bool, code: ?int, message: string, globalName: ?string}
     * @param callable|null         $out       receives each output line, without its newline
     */
    public static function run(array $env, ?callable $connector = null, ?callable $out = null): int
    {
        $out ??= static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
        };

        $username = self::setting($env, 'ORACLE_USERNAME');
        $password = self::setting($env, 'ORACLE_PASSWORD');
        $tns = self::setting($env, 'ORACLE_TNS');
        $host = self::setting($env, 'ORACLE_HOST');
        $port = self::setting($env, 'ORACLE_PORT');
        $service = self::setting($env, 'ORACLE_SERVICE_NAME');

        if ($username === '' && $tns === '' && $host === '') {
            if (self::setting($env, 'ORACLE_CHECK_VERBOSE') !== '') {
                $out('[oracle] not configured (no ORACLE_* settings), skipping');
            }

            return 0;
        }

        $required = $tns !== ''
            ? ['ORACLE_USERNAME', 'ORACLE_PASSWORD', 'ORACLE_TNS']
            : ['ORACLE_USERNAME', 'ORACLE_PASSWORD', 'ORACLE_HOST', 'ORACLE_PORT', 'ORACLE_SERVICE_NAME'];
        $missing = false;
        foreach ($required as $name) {
            if (self::setting($env, $name) === '') {
                $out("[oracle] {$name} is empty. Set it in the app's environment settings (on Citadel, the dashboard).");
                $missing = true;
            }
        }
        if ($missing) {
            return 1;
        }

        [$addressHost, $addressPort, $connectString] = $tns !== ''
            ? self::parseTns($tns)
            : [$host, $port, "{$host}:{$port}/{$service}"];

        if (filter_var($addressHost, FILTER_VALIDATE_IP) === false && gethostbyname($addressHost) === $addressHost) {
            $out('[oracle] the host in ORACLE_HOST (or ORACLE_TNS) does not resolve from here. Check the spelling, and that you are on the campus network or VPN.');

            return 1;
        }

        $socket = @stream_socket_client('tcp://' . $addressHost . ':' . $addressPort, $errno, $error, self::PORT_TIMEOUT);
        if ($socket === false) {
            $out('[oracle] Oracle did not answer at the host and port in your settings within 3 seconds. On Citadel: on the app\'s dashboard, tick the Oracle service under Services Available and click Request Access Changes. Locally: check the VPN.');

            return 1;
        }
        fclose($socket);

        if ($connector === null && !extension_loaded('oci8')) {
            $out('[oracle] Oracle is set up for this app, but this PHP has no Oracle driver (oci8). Deploy to Citadel, which has it, or use the WSU Sail or Docker image with Oracle.');

            return 1;
        }

        $result = $connector !== null
            ? $connector($username, $password, $connectString)
            : self::realConnect($username, $password, $connectString);

        if ($result['ok'] !== true) {
            $out(self::errorMessage($result['code'] ?? null));

            return 1;
        }

        $name = $result['globalName'] ?? null;
        $label = is_string($name) && $name !== '' ? $name : 'unknown';
        $oci8 = extension_loaded('oci8') ? (string) phpversion('oci8') : 'unknown';
        $client = defined('OCI_CLIENT_VERSION') ? self::clientVersion(OCI_CLIENT_VERSION) : 'unknown';
        $out(sprintf('[oracle] connected to %s (oci8 %s, client %s)', $label, $oci8, $client));

        return 0;
    }

    /** @param array<string, string> $env */
    private static function setting(array $env, string $name): string
    {
        $value = $env[$name] ?? '';

        return trim(is_scalar($value) ? (string) $value : '');
    }

    /**
     * Splits a TNS value into host, port and the connect string the driver gets (the value itself, unchanged).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function parseTns(string $tns): array
    {
        if (preg_match('/^\s*\(/', $tns) === 1) {
            $host = self::descriptorPart($tns, 'HOST');
            $port = self::descriptorPart($tns, 'PORT');

            return [$host, $port, $tns];
        }

        if (str_contains($tns, ':')) {
            [$hostPort, $service] = array_pad(explode('/', $tns, 2), 2, '');
            [$host, $port] = array_pad(explode(':', $hostPort, 2), 2, '');

            return [$host, $port, $tns];
        }

        [$host, $service] = array_pad(explode('/', $tns, 2), 2, '');

        return [$host, '1521', $tns];
    }

    private static function descriptorPart(string $descriptor, string $key): string
    {
        if (preg_match('/' . $key . '\s*=\s*([^)\s]+)/i', $descriptor, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * The real driver connect: oci_connect, then exactly one bounded query for the database name.
     *
     * @return array{ok: bool, code: ?int, message: string, globalName: ?string}
     */
    private static function realConnect(string $user, string $password, string $connectString): array
    {
        $connection = @oci_connect($user, $password, $connectString);
        if ($connection === false) {
            $error = oci_error(null);

            return [
                'ok' => false,
                'code' => isset($error['code']) ? (int) $error['code'] : null,
                'message' => (string) ($error['message'] ?? ''),
                'globalName' => null,
            ];
        }

        $statement = oci_parse($connection, 'SELECT global_name FROM global_name');
        $globalName = null;
        if ($statement === false || oci_execute($statement) !== true) {
            $error = oci_error($statement !== false ? $statement : $connection);

            return [
                'ok' => false,
                'code' => isset($error['code']) ? (int) $error['code'] : null,
                'message' => (string) ($error['message'] ?? ''),
                'globalName' => null,
            ];
        }

        $row = oci_fetch_array($statement, OCI_A_ASSOC);
        if (is_array($row) && isset($row['GLOBAL_NAME'])) {
            $globalName = (string) $row['GLOBAL_NAME'];
        }
        oci_free_statement($statement);
        oci_close($connection);

        return ['ok' => true, 'code' => null, 'message' => '', 'globalName' => $globalName];
    }

    /** The Oracle error code as a sentence. Codes 1031 and 942 share one sentence, with their own number. */
    private static function errorMessage(?int $code): string
    {
        return match ($code) {
            12154 => '[oracle] Oracle could not find the connect name in ORACLE_TNS. Use host:port/service or a full descriptor.',
            12514 => '[oracle] the Oracle listener answered but that service is not available. Check ORACLE_SERVICE_NAME; the database may be in maintenance.',
            12541 => '[oracle] there is no Oracle listener at that address. Check the host and port; the database may be down.',
            1017 => '[oracle] Oracle refused the username or password. Check ORACLE_USERNAME and ORACLE_PASSWORD.',
            1031, 942 => sprintf('[oracle] the login worked, but this account lacks permission on that table or view (ORA-%d). Ask the data owner for the grant.', $code),
            null => '[oracle] Oracle returned ORA-unknown. Search that code, and check the settings above.',
            default => sprintf('[oracle] Oracle returned ORA-%d. Search that code, and check the settings above.', $code),
        };
    }

    /** Formats an OCI_CLIENT_VERSION number such as 210700000 as 21.7. */
    private static function clientVersion(int $version): string
    {
        return intdiv($version, 10_000_000) . '.' . intdiv($version % 10_000_000, 100_000);
    }
}

if (($_SERVER['SCRIPT_FILENAME'] ?? null) !== null && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $env = getenv();
    foreach ($_SERVER['argv'] ?? [] as $argument) {
        if ($argument === '--verbose') {
            $env['ORACLE_CHECK_VERBOSE'] = '1';
        }
    }
    exit(SquireOracleCheck::run($env, null, null));
}
