<?php

namespace App\Console\Commands;

use App\Exceptions\DokuRequestException;
use App\Services\Doku\DokuClient;
use App\Services\Doku\DokuDirectDebitAdapter;
use App\Services\Doku\DokuExchangeSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sandbox-only diagnostic for the DANA create rejection (HTTP 500 / 5005400). It never touches the database: no booking,
 * intent or attempt is read or written, and every probe uses its own throwaway invoice and X-EXTERNAL-ID. Nothing is retried.
 *
 * A = minimal body from the DOKU documentation, B = the body this backend builds today, C = A with the tunnel return URL,
 * D = A + validUpTo (as built for B), E = A + pointOfInitiation (as built for B),
 * F = A with X-TIMESTAMP in Asia/Jakarta, G = F + X-DEVICE-ID + X-IP-ADDRESS.
 */
class ProbeDokuDanaCreate extends Command
{
    private const ACCEPTED_CODE = '2005400';

    protected $signature = 'doku:probe-dana-create
        {--public-url=https://example.com/payment/return : Ordinary public return URL used by probes A, D, E, F and G}
        {--tunnel-url= : Return URL used by probe C (defaults to DOKU_EWALLET_RETURN_URL)}
        {--device-id= : Alphanumeric X-DEVICE-ID for probe G (defaults to a random one)}
        {--ip-address=203.0.113.10 : IPv4 X-IP-ADDRESS for probe G}
        {--dry-run : Print bodies, non-secret headers and the A/B diff, send nothing}';

    protected $description = 'Send seven DANA create probes to the DOKU Sandbox (no database writes) and compare minimal vs backend body';

    public function handle(DokuClient $client, DokuDirectDebitAdapter $adapter): int
    {
        if ($client->environment() !== 'sandbox') {
            $this->error('Refusing to run: DOKU_ENVIRONMENT is not sandbox.');

            return self::FAILURE;
        }
        $publicUrl = (string) $this->option('public-url');
        $tunnelUrl = (string) ($this->option('tunnel-url') ?: config('doku.ewallet.return_url'));
        foreach (['public-url' => $publicUrl, 'tunnel-url' => $tunnelUrl] as $name => $url) {
            if (! str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
                $this->error("--{$name} must be a valid https URL.");

                return self::FAILURE;
            }
        }
        $deviceId = (string) ($this->option('device-id') ?: Str::random(20));
        $ipAddress = (string) $this->option('ip-address');
        if (preg_match('/^[A-Za-z0-9]{1,64}$/D', $deviceId) !== 1) {
            $this->error('--device-id must be alphanumeric (1-64 characters, no symbols).');

            return self::FAILURE;
        }
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->error('--ip-address must be a valid IPv4 address.');

            return self::FAILURE;
        }
        if ($adapter->paymentPath('dana') === null || ! $client->configured()) {
            $this->error('DOKU is not configured for the Sandbox (client id, secret, private key, webhook URL).');

            return self::FAILURE;
        }

        $path = (string) $adapter->paymentPath('dana');
        $b = $adapter->createBody('dana', (object) [
            'merchant_reference' => $this->invoice('B'), 'amount' => 100000, 'currency' => 'IDR',
        ], CarbonImmutable::now('UTC')->addHour());
        $probes = [
            'A' => ['title' => 'A (documented minimal)', 'body' => $this->minimalBody('A', $publicUrl)],
            'B' => ['title' => 'B (current backend build)', 'body' => $b],
            'C' => ['title' => 'C (A with tunnel URL)', 'body' => $this->minimalBody('C', $tunnelUrl)],
            'D' => ['title' => 'D (A + validUpTo)', 'body' => $this->minimalBody('D', $publicUrl) + ['validUpTo' => $b['validUpTo']]],
            'E' => ['title' => 'E (A + pointOfInitiation)', 'body' => $this->minimalBody('E', $publicUrl) + ['pointOfInitiation' => $b['pointOfInitiation']]],
            'F' => ['title' => 'F (A with X-TIMESTAMP in Asia/Jakarta)', 'body' => $this->minimalBody('F', $publicUrl), 'timezone' => 'Asia/Jakarta'],
            'G' => ['title' => 'G (F + X-DEVICE-ID + X-IP-ADDRESS)', 'body' => $this->minimalBody('G', $publicUrl), 'timezone' => 'Asia/Jakarta',
                'headers' => ['X-DEVICE-ID' => $deviceId, 'X-IP-ADDRESS' => $ipAddress]],
        ];
        foreach ($probes as $name => $probe) {
            $probes[$name]['external_id'] = (string) random_int(100000000000000000, 999999999999999999);
        }

        $this->line('Endpoint: POST '.$path);
        foreach ($probes as $probe) {
            $this->newLine();
            $this->info('Probe '.$probe['title']);
            $this->line(json_encode($probe['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line('Headers (secrets masked; X-TIMESTAMP is an example, the real value is generated at send time):');
            foreach ($this->displayHeaders($probe) as $header => $value) {
                $this->line("  {$header}: {$value}");
            }
        }

        $this->newLine();
        $this->info('Diff A vs B (partnerReferenceNo differs by design)');
        $this->printDiff($probes['A']['body'], $probes['B']['body']);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run: nothing was sent.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($probes as $name => $probe) {
            $rows[] = [$name, ...$this->send($client, $path, $probe)];
        }
        $this->newLine();
        $this->table(['Probe', 'HTTP', 'responseCode', 'Result', 'Note'], $rows);
        $this->line('PASS = DOKU answered '.self::ACCEPTED_CODE.'. Failed probes keep their sanitized request/response snapshot in storage/logs/doku-diagnostics-*.log.');

        return self::SUCCESS;
    }

    /**
     * Non-secret request headers as they will be sent; the signature and bearer token are shown masked.
     *
     * @param  array{external_id: string, timezone?: string, headers?: array<string, string>}  $probe
     * @return array<string, string>
     */
    private function displayHeaders(array $probe): array
    {
        return ['X-PARTNER-ID' => (string) config('doku.client_id'), 'X-EXTERNAL-ID' => $probe['external_id'],
            'X-TIMESTAMP' => isset($probe['timezone']) ? CarbonImmutable::now($probe['timezone'])->format('Y-m-d\TH:i:sP') : CarbonImmutable::now('UTC')->toIso8601String(),
            'X-SIGNATURE' => DokuExchangeSanitizer::REDACTED, 'Authorization' => DokuExchangeSanitizer::REDACTED,
            'Content-Type' => 'application/json', 'Accept' => 'application/json'] + ($probe['headers'] ?? []);
    }

    /** @return array<string, mixed> */
    private function minimalBody(string $probe, string $returnUrl): array
    {
        return [
            'partnerReferenceNo' => $this->invoice($probe),
            'amount' => ['value' => '100000.00', 'currency' => 'IDR'],
            'urlParam' => ['url' => $returnUrl, 'type' => 'PAY_RETURN', 'isDeepLink' => 'N'],
            'additionalInfo' => ['channel' => 'EMONEY_DANA_SNAP'],
        ];
    }

    private function invoice(string $probe): string
    {
        return 'PROBE-'.$probe.'-'.strtoupper(Str::random(14));
    }

    /**
     * @param  array{body: array<string, mixed>, external_id: string, timezone?: string, headers?: array<string, string>}  $probe
     * @return array{0: string, 1: string, 2: string, 3: string} HTTP status, DOKU responseCode, PASS|FAIL, note
     */
    private function send(DokuClient $client, string $path, array $probe): array
    {
        $options = array_filter(['timezone' => $probe['timezone'] ?? null, 'headers' => $probe['headers'] ?? null]);
        try {
            $response = $client->signedRequest($path, $probe['body'], $probe['external_id'], null, ['attempt_guid' => null, 'channel' => 'dana'], $options);
        } catch (DokuRequestException $e) {
            return [(string) ($e->httpStatus ?? '-'), (string) ($e->providerResponseCode ?? '-'), 'FAIL',
                $e->phase === 'b2b_token' ? 'token failed, payment not sent' : 'rejected by DOKU'];
        } catch (ConnectionException) {
            return ['-', '-', 'FAIL', 'transport timeout (outcome unknown)'];
        } catch (Throwable $e) {
            return ['-', '-', 'FAIL', 'internal '.$e::class];
        }
        $code = $client->safeProviderCode($response['responseCode'] ?? null);
        $host = parse_url((string) ($response['webRedirectUrl'] ?? ''), PHP_URL_HOST);

        return [(string) ($client->lastHttpStatus() ?? '-'), $code ?? '-', $code === self::ACCEPTED_CODE ? 'PASS' : 'FAIL',
            is_string($host) ? 'redirect host '.$host : 'no redirect URL'];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function printDiff(array $a, array $b): void
    {
        $flatA = $this->flatten($a);
        $flatB = $this->flatten($b);
        $lines = 0;
        foreach (array_unique([...array_keys($flatA), ...array_keys($flatB)]) as $key) {
            if ($key === 'partnerReferenceNo') {
                continue;
            }
            if (! array_key_exists($key, $flatB)) {
                $this->line("  only in A : {$key} = {$flatA[$key]}");
            } elseif (! array_key_exists($key, $flatA)) {
                $this->line("  only in B : {$key} = {$flatB[$key]}");
            } elseif ($flatA[$key] !== $flatB[$key]) {
                $this->line("  differs   : {$key}  A={$flatA[$key]}  B={$flatB[$key]}");
            } else {
                continue;
            }
            $lines++;
        }
        if ($lines === 0) {
            $this->line('  (identical apart from partnerReferenceNo)');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            is_array($value)
                ? $flat += $this->flatten($value, $prefix.$key.'.')
                : $flat[$prefix.$key] = (string) $value;
        }

        return $flat;
    }
}
