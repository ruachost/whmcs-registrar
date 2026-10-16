<?php
/**
 * Ruachost — WHMCS domain registrar module for Ruachost reseller partners.
 *
 * Install in <whmcs>/modules/registrars/ruachost/, then activate it under
 * Configuration → System Settings → Domain Registrars and enter the API key and secret
 * Ruachost issued to you. Domains are paid from your prepaid Ruachost credit.
 */

use WHMCS\Database\Capsule;
use WHMCS\Domains\DomainLookup\ResultsList as LookupResults;
use WHMCS\Domains\DomainLookup\SearchResult;
use RuachostRegistrar\ApiError;
use RuachostRegistrar\Client;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Ruachost's live reseller API. Partners can override it in the module settings. */
if (!defined('RUACHOST_API_ENDPOINT')) {
    define('RUACHOST_API_ENDPOINT', 'https://dashboard.ruachost.com/modules/addons/ruachost_reseller/api.php');
}
if (!defined('RUACHOST_MODULE_VERSION')) {
    define('RUACHOST_MODULE_VERSION', '1.1.0');
}

require_once __DIR__ . '/lib/Signature.php';
require_once __DIR__ . '/lib/Client.php';

/* ------------------------------------------------------------------ setup */

function ruachost_MetaData()
{
    return [
        'DisplayName' => 'Ruachost',
        'APIVersion'  => '1.1',
    ];
}

function ruachost_getConfigArray()
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Ruachost'],
        'Description'  => [
            'Type'  => 'System',
            'Value' => 'Register, transfer and renew domains through Ruachost, paid from your prepaid '
                . 'Ruachost credit. Ask Ruachost for your API key and secret.',
        ],
        'ApiKey' => [
            'FriendlyName' => 'API key',
            'Type'         => 'text',
            'Size'         => '40',
            'Description'  => 'Starts with rh_',
        ],
        'ApiSecret' => [
            'FriendlyName' => 'API secret',
            'Type'         => 'password',
            'Size'         => '70',
        ],
        'ApiEndpoint' => [
            'FriendlyName' => 'API endpoint (optional)',
            'Type'         => 'text',
            'Size'         => '70',
            'Description'  => 'Leave blank unless Ruachost asks you to change it.',
        ],
        'Timeout' => [
            'FriendlyName' => 'Timeout (seconds)',
            'Type'         => 'text',
            'Size'         => '4',
            'Default'      => '90',
        ],
        'DebugLog' => [
            'FriendlyName' => 'Debug logging',
            'Type'         => 'yesno',
            'Description'  => 'Log every call to Configuration → System Logs → Module Log. Errors are always '
                . 'logged. EPP codes, contacts and your secret are never logged.',
        ],
    ];
}

/** Runs when the admin saves the registrar settings: confirms the credentials work. */
function ruachost_config_validate($params)
{
    $problem = null;
    try {
        Client::fromParams($params)->call('account.capabilities');
    } catch (ApiError $e) {
        $problem = ruachost_friendlyError($e);
    }
    if ($problem !== null && class_exists('\WHMCS\Exception\Module\InvalidConfiguration')) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration($problem);
    }
}

/* ------------------------------------------------------------ order flows */

function ruachost_RegisterDomain($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $years = (int) $params['regperiod'];
        ruachost_validateOrder($params, $years);
        $data = $client->call('domain.register', ruachost_orderPayload($params) + ['years' => $years],
            ruachost_requestId('register', $params, (string) $years));
        return ruachost_orderResult($data, 'registration');
    }, $params);
}

function ruachost_TransferDomain($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $years = (int) ($params['regperiod'] ?? 1);
        $epp = (string) ($params['eppcode'] ?? ($params['transfersecret'] ?? ''));
        ruachost_validateOrder($params, $years);
        if (trim($epp) === '') {
            return ['error' => 'An EPP / transfer code is required.'];
        }
        $data = $client->call('domain.transfer', ruachost_orderPayload($params) + ['years' => $years, 'epp_code' => $epp],
            ruachost_requestId('transfer', $params, $years . '|' . hash('sha256', $epp)));
        return ruachost_orderResult($data, 'transfer');
    }, $params);
}

function ruachost_RenewDomain($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $years = (int) $params['regperiod'];
        // Keyed to the current expiry date: a retry of this renewal replays the
        // stored result, while next year's renewal gets a fresh ID.
        $expiry = (string) Capsule::table('tbldomains')->where('id', (int) $params['domainid'])->value('expirydate');
        if ($years < 1 || $years > 10) {
            return ['error' => 'Renewal term must be 1 to 10 years.'];
        }
        $client->call('domain.renew', ['domain' => ruachost_domain($params), 'years' => $years],
            ruachost_requestId('renew', $params, $years . '|' . $expiry));
        return ['success' => true];
    }, $params);
}

/* ----------------------------------------------------------- management */

function ruachost_GetNameservers($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $data = $client->call('domain.nameservers.get', ['domain' => ruachost_domain($params)]);
        $out = [];
        foreach (array_values($data['nameservers'] ?? []) as $i => $ns) {
            $out['ns' . ($i + 1)] = $ns;
        }
        return $out;
    }, $params);
}

function ruachost_SaveNameservers($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $client->call('domain.nameservers.set', [
            'domain'      => ruachost_domain($params),
            'nameservers' => ruachost_nameservers($params),
        ]);
        return ['success' => true];
    }, $params);
}

function ruachost_GetRegistrarLock($params)
{
    try {
        $data = Client::fromParams($params)->call('domain.lock.get', ['domain' => ruachost_domain($params)]);
        return !empty($data['locked']) ? 'locked' : 'unlocked';
    } catch (ApiError $e) {
        return ''; // WHMCS hides the lock option when the status is unknown
    }
}

function ruachost_SaveRegistrarLock($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $client->call('domain.lock.set', [
            'domain' => ruachost_domain($params),
            'locked' => ($params['lockenabled'] ?? '') === 'locked',
        ]);
        return ['success' => true];
    }, $params);
}

function ruachost_GetContactDetails($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $data = $client->call('domain.contacts.get', ['domain' => ruachost_domain($params)]);
        return array_filter((array) ($data['contacts'] ?? []), 'is_array');
    }, $params);
}

function ruachost_SaveContactDetails($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $client->call('domain.contacts.set', [
            'domain'   => ruachost_domain($params),
            'contacts' => (array) ($params['contactdetails'] ?? []),
        ]);
        return ['success' => true];
    }, $params);
}

function ruachost_GetEPPCode($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $data = $client->call('domain.epp', ['domain' => ruachost_domain($params)]);
        // An empty array tells WHMCS the code was emailed to the registrant.
        return !empty($data['epp_code']) ? ['eppcode' => $data['epp_code']] : [];
    }, $params);
}

function ruachost_IDProtectToggle($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $client->call('domain.idprotect.set', [
            'domain'  => ruachost_domain($params),
            'enabled' => !empty($params['protectenable']),
        ]);
        return ['success' => true];
    }, $params);
}

/* ------------------------------------------------------------------ sync */

function ruachost_Sync($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $data = $client->call('domain.info', ['domain' => ruachost_domain($params)]);
        $status = (string) ($data['status'] ?? '');
        $out = [
            'active'          => $status === 'Active',
            'expired'         => in_array($status, ['Expired', 'Grace', 'Redemption'], true),
            'transferredAway' => $status === 'Transferred Away',
        ];
        if (!empty($data['expiry_date'])) {
            $out['expirydate'] = $data['expiry_date'];
        }
        return $out;
    }, $params);
}

function ruachost_TransferSync($params)
{
    return ruachost_run(function (Client $client) use ($params) {
        $data = $client->call('domain.info', ['domain' => ruachost_domain($params)]);
        $status = (string) ($data['status'] ?? '');
        if ($status === 'Active') {
            return ['completed' => true] + (!empty($data['expiry_date']) ? ['expirydate' => $data['expiry_date']] : []);
        }
        if (in_array($status, ['Cancelled', 'Fraud'], true)) {
            return ['failed' => true, 'reason' => 'The transfer was cancelled at Ruachost.'];
        }
        return []; // still pending
    }, $params);
}

/* --------------------------------------------------------- domain lookup */

function ruachost_CheckAvailability($params)
{
    $sld = !empty($params['isIdnDomain']) && !empty($params['punyCodeSearchTerm'])
        ? (string) $params['punyCodeSearchTerm']
        : (string) $params['searchTerm'];
    $tlds = array_values(array_filter((array) ($params['tldsToInclude'] ?? [])));

    $results = new LookupResults();
    if ($sld === '' || !$tlds) {
        return $results;
    }

    try {
        $client = Client::fromParams($params);
        foreach (array_chunk($tlds, 30) as $chunk) {
            $domains = array_map(fn ($tld) => strtolower($sld) . '.' . ltrim($tld, '.'), $chunk);
            $data = $client->call('domain.check', ['domains' => $domains]);
            $byDomain = [];
            foreach ((array) ($data['results'] ?? []) as $row) {
                $byDomain[$row['domain']] = $row['status'];
            }
            foreach ($chunk as $i => $tld) {
                $result = new SearchResult(strtolower($sld), ltrim($tld, '.'));
                switch ($byDomain[$domains[$i]] ?? 'unknown') {
                    case 'available':
                        $result->setStatus(SearchResult::STATUS_NOT_REGISTERED);
                        break;
                    case 'registered':
                        $result->setStatus(SearchResult::STATUS_REGISTERED);
                        break;
                    case 'tld_not_supported':
                        $result->setStatus(SearchResult::STATUS_TLD_NOT_SUPPORTED);
                        break;
                    default:
                        $result->setStatus(SearchResult::STATUS_UNKNOWN);
                }
                $results->append($result);
            }
        }
    } catch (ApiError $e) {
        return ['error' => 'Ruachost: ' . $e->getMessage()];
    }
    return $results;
}

/** Utilities → Registrar TLD Sync: imports your Ruachost cost prices. */
function ruachost_GetTldPricing($params)
{
    try {
        $data = Client::fromParams($params)->call('pricing.tlds');
    } catch (ApiError $e) {
        return ['error' => 'Ruachost: ' . $e->getMessage()];
    }

    $currency = (string) ($data['currency']['code'] ?? '');
    $results = new \WHMCS\Results\ResultsList();

    foreach ((array) ($data['tlds'] ?? []) as $tld => $row) {
        $register = (array) ($row['register'] ?? []);
        if (!$register) {
            continue;
        }
        $years = array_map('intval', array_keys($register));
        sort($years);
        $minYears = $years[0];
        $perTerm = function (array $prices) use ($minYears) {
            $value = $prices['1'] ?? ($prices[(string) $minYears] ?? null);
            return $value === null || $value === '' || (float) $value < 0 ? null : (float) $value;
        };

        $item = (new \WHMCS\Domain\TopLevel\ImportItem())
            ->setExtension('.' . ltrim((string) $tld, '.'))
            ->setYears($years)
            ->setMinYears($minYears)
            ->setMaxYears(end($years))
            ->setRegisterPrice($perTerm($register))
            ->setRenewPrice($perTerm((array) ($row['renew'] ?? [])))
            ->setTransferPrice($perTerm((array) ($row['transfer'] ?? [])))
            ->setEppRequired(true)
            ->setCurrency($currency);
        $results->append($item);
    }
    return $results;
}

/* --------------------------------------------------------------- helpers */

/** Runs an API call and converts errors into the array WHMCS expects. */
function ruachost_run(callable $fn, array $params)
{
    try {
        return $fn(Client::fromParams($params));
    } catch (ApiError $e) {
        return ['error' => ruachost_friendlyError($e)];
    } catch (\Throwable $e) {
        if (function_exists('logModuleCall')) {
            logModuleCall('ruachost', 'exception', [], (string) $e);
        }
        return ['error' => 'Ruachost module error: ' . $e->getMessage()];
    }
}

function ruachost_friendlyError(ApiError $e): string
{
    switch ($e->errorCode) {
        case 'insufficient_funds':
            $message = 'Your Ruachost credit balance is too low. ' . $e->getMessage();
            break;
        case 'unauthorized':
        case 'stale_request':
        case 'ip_not_allowed':
        case 'account_suspended':
            $message = 'Ruachost rejected the connection: ' . $e->getMessage();
            break;
        case 'rate_limited':
            $message = 'Ruachost is limiting requests from your account. Wait a minute and try again.';
            break;
        default:
            $message = 'Ruachost: ' . $e->getMessage();
    }
    // The reference lets Ruachost support find the exact request.
    return $e->requestId !== '' ? $message . ' (Ref: ' . $e->requestId . ')' : $message;
}

/** Basic checks before an order leaves this WHMCS, so obvious mistakes fail fast and free. */
function ruachost_validateOrder(array $params, int $years): void
{
    if ($years < 1 || $years > 10) {
        throw new ApiError('invalid_request', 'Registration term must be 1 to 10 years.');
    }
    $country = strtoupper((string) ($params['countrycode'] ?? ($params['country'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        throw new ApiError('invalid_contact', 'The registrant country must be a 2-letter country code.');
    }
    if (!filter_var((string) ($params['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('invalid_contact', 'The registrant email address is not valid.');
    }
    foreach (['firstname', 'lastname', 'address1', 'city', 'postcode'] as $field) {
        if (trim((string) ($params[$field] ?? '')) === '') {
            throw new ApiError('invalid_contact', "The registrant {$field} is missing.");
        }
    }
}

/** Maps Ruachost's order state onto what WHMCS expects from Register/TransferDomain. */
function ruachost_orderResult(array $data, string $what): array
{
    $state = (string) ($data['state'] ?? 'completed');
    if ($what === 'registration' && $state === 'processing' && ($data['status'] ?? '') !== 'Active') {
        // WHMCS marks the domain "Pending Registration"; Domain Sync completes it later.
        return [
            'success'        => true,
            'pending'        => true,
            'pendingMessage' => 'Ruachost accepted the order (' . ($data['order_ref'] ?? '') . '). '
                . 'The registry is still processing it; domain sync will update the status.',
        ];
    }
    return ['success' => true];
}

function ruachost_domain(array $params): string
{
    return strtolower($params['sld'] . '.' . ltrim((string) $params['tld'], '.'));
}

/** @return string[] */
function ruachost_nameservers(array $params): array
{
    $ns = [];
    for ($i = 1; $i <= 5; $i++) {
        if (!empty($params['ns' . $i])) {
            $ns[] = trim((string) $params['ns' . $i]);
        }
    }
    return $ns;
}

/** Shared payload for register and transfer orders. */
function ruachost_orderPayload(array $params): array
{
    $payload = [
        'domain'           => ruachost_domain($params),
        'nameservers'      => ruachost_nameservers($params),
        'id_protection'    => !empty($params['idprotection']),
        'dns_management'   => !empty($params['dnsmanagement']),
        'email_forwarding' => !empty($params['emailforwarding']),
        'registrant'       => [
            'firstname'   => $params['firstname'] ?? '',
            'lastname'    => $params['lastname'] ?? '',
            'companyname' => $params['companyname'] ?? '',
            'email'       => $params['email'] ?? '',
            'address1'    => $params['address1'] ?? '',
            'address2'    => $params['address2'] ?? '',
            'city'        => $params['city'] ?? '',
            'state'       => $params['state'] ?? ($params['fullstate'] ?? ''),
            'postcode'    => $params['postcode'] ?? '',
            'country'     => $params['countrycode'] ?? ($params['country'] ?? ''),
            'phonenumber' => $params['fullphonenumber'] ?? ($params['phonenumber'] ?? ''),
        ],
    ];
    if (!empty($params['additionalfields']) && is_array($params['additionalfields'])) {
        $payload['additional_fields'] = $params['additionalfields'];
    }
    return $payload;
}

/** Deterministic request ID so retries of the same action are never charged twice. */
function ruachost_requestId(string $action, array $params, string $extra = ''): string
{
    return 'rr-' . substr(hash('sha256', implode('|', [
        $action, (int) ($params['domainid'] ?? 0), ruachost_domain($params), $extra,
    ])), 0, 40);
}
