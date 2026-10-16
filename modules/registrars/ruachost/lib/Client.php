<?php

namespace RuachostRegistrar;

/** Error returned by the Ruachost API (or the connection to it). */
class ApiError extends \RuntimeException
{
    public string $errorCode;
    public string $requestId;

    public function __construct(string $errorCode, string $message, string $requestId = '')
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->requestId = $requestId;
    }
}

/** Signed JSON client for the Ruachost reseller API. */
class Client
{
    const MODULE = 'ruachost';

    /** Keys whose values never reach the WHMCS module log. */
    const REDACT = [
        'epp_code', 'eppcode', 'registrant', 'contacts', 'firstname', 'lastname', 'companyname', 'email',
        'address1', 'address2', 'city', 'state', 'postcode', 'phonenumber', 'fullphonenumber',
    ];

    protected string $endpoint;
    protected string $key;
    protected string $secret;
    protected int $timeout;
    protected bool $debug;

    public function __construct(string $endpoint, string $key, string $secret, int $timeout = 90, bool $debug = false)
    {
        $this->endpoint = $endpoint;
        $this->key = $key;
        $this->secret = $secret;
        $this->timeout = max(10, $timeout);
        $this->debug = $debug;
    }

    public static function fromParams(array $params): self
    {
        $endpoint = trim((string) ($params['ApiEndpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = RUACHOST_API_ENDPOINT;
        }
        if ($endpoint === '' || stripos($endpoint, 'https://') !== 0) {
            throw new ApiError('config', 'The Ruachost API endpoint is not configured (it must start with https://).');
        }
        if (trim((string) ($params['ApiKey'] ?? '')) === '' || trim((string) ($params['ApiSecret'] ?? '')) === '') {
            throw new ApiError('config', 'Enter your Ruachost API key and secret in Setup → Domain Registrars.');
        }
        return new self(
            $endpoint,
            trim((string) $params['ApiKey']),
            trim((string) $params['ApiSecret']),
            (int) ($params['Timeout'] ?? 90),
            in_array((string) ($params['DebugLog'] ?? ''), ['on', '1', 'yes'], true)
        );
    }

    /**
     * @param string|null $requestId Pass a deterministic ID for money-moving calls so
     *                               a retry is recognised and never charged twice.
     */
    public function call(string $op, array $params = [], ?string $requestId = null): array
    {
        $body = json_encode(['op' => $op, 'params' => $params], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $requestId = $requestId ?: 'rq-' . bin2hex(random_bytes(16));
        $timestamp = (string) time();

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: RuachostWHMCSRegistrar/' . RUACHOST_MODULE_VERSION,
                'X-RH-Key: ' . $this->key,
                'X-RH-Timestamp: ' . $timestamp,
                'X-RH-Request-Id: ' . $requestId,
                'X-RH-Signature: ' . Signature::sign($this->secret, $this->key, $timestamp, $requestId, $body),
            ],
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $failed = $raw === false || !is_array($decoded) || empty($decoded['success']);
        $this->log($op, $requestId, $params, $raw === false ? $curlError : $decoded, $failed);

        if ($raw === false) {
            throw new ApiError('connection', 'Could not reach Ruachost: ' . $curlError, $requestId);
        }
        if (!is_array($decoded)) {
            throw new ApiError('bad_response', "Unexpected response from Ruachost (HTTP {$httpCode}).", $requestId);
        }
        if (empty($decoded['success'])) {
            throw new ApiError(
                (string) ($decoded['error']['code'] ?? 'error'),
                (string) ($decoded['error']['message'] ?? "Ruachost returned an error (HTTP {$httpCode})."),
                (string) ($decoded['request_id'] ?? $requestId)
            );
        }
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /**
     * Writes to Configuration → System Logs → Module Log. Failures are always logged;
     * successful calls only when "Debug logging" is on. EPP codes, contacts and the
     * credentials are always removed first.
     */
    protected function log(string $op, string $requestId, array $params, $response, bool $failed): void
    {
        if (!function_exists('logModuleCall') || (!$failed && !$this->debug)) {
            return;
        }
        $replace = [$this->secret, $this->key];
        array_walk_recursive($params, function ($value, $key) use (&$replace) {
            if (in_array(strtolower((string) $key), ['epp_code', 'eppcode'], true) && is_string($value) && $value !== '') {
                $replace[] = $value;
            }
        });
        $safeResponse = is_array($response) ? self::redact($response) : $response;
        logModuleCall(
            self::MODULE,
            $op,
            ['request_id' => $requestId, 'params' => self::redact($params)],
            is_array($safeResponse) ? json_encode($safeResponse, JSON_UNESCAPED_SLASHES) : (string) $safeResponse,
            null,
            array_values(array_filter($replace, 'strlen'))
        );
    }

    public static function redact($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = is_string($key) && in_array(strtolower($key), self::REDACT, true) && $item !== null && $item !== ''
                ? '[redacted]'
                : self::redact($item);
        }
        return $out;
    }
}
