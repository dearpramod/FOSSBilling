<?php

declare(strict_types=1);

/**
 * Regression coverage for the cold-intent invoice-binding fix (2026-09-09).
 *
 * processTransaction() must never credit an invoice whose id was resolved from
 * ipn.php's raw, browser-supplied invoice_id param (the "cold intent" fallback) —
 * only from the server-written pending-intent file written at initiation.
 * reconcilePending() must not delete a pending-intent file for a Fonepay-reported
 * "success" unless it was actually settled — deleting it regardless was the specific
 * mechanism that could produce a cold-but-real reference for the above to exploit.
 */

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tests\Helpers\DummyBean;

use function Tests\Helpers\container;

function buildFonepayTransaction(int $id): Model_Transaction
{
    $tx = new Model_Transaction();
    $tx->loadBean(new DummyBean());
    $tx->id = $id;

    return $tx;
}

function setFonepayPrivateProperty(object $obj, string $property, mixed $value): void
{
    $reflection = new ReflectionClass($obj);
    $prop = $reflection->getProperty($property);
    $prop->setValue($obj, $value);
}

function invokeFonepayPrivateMethod(object $obj, string $method, array $args = []): mixed
{
    $reflection = new ReflectionClass($obj);
    $methodObj = $reflection->getMethod($method);

    return $methodObj->invokeArgs($obj, $args);
}

function fonepayConfig(): array
{
    $keypair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keypair, $pem);

    return [
        'test_mode' => true,
        'merchant_username' => 'user_' . uniqid(),
        'merchant_password' => 'pass',
        'private_key' => $pem,
        'terminal_id' => 'TERM01',
        'gateway_id' => 5,
    ];
}

function jsonResponse(array $body): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getContent')->with(false)->andReturn(json_encode($body));

    return $response;
}

/** Mocks the login call + the thirdPartyDynamicQrGetStatus call, in that order. */
function mockFonepayStatusCalls(object $adapter, array $statusBody): void
{
    $loginResponse = jsonResponse(['accessToken' => 'tok_' . uniqid(), 'expiresIn' => 3600]);
    $statusResponse = jsonResponse($statusBody);

    $httpClientMock = Mockery::mock(HttpClientInterface::class);
    $httpClientMock->shouldReceive('request')
        ->once()
        ->with('POST', Mockery::pattern('#/login$#'), Mockery::any())
        ->andReturn($loginResponse);
    $httpClientMock->shouldReceive('request')
        ->once()
        ->with('POST', Mockery::pattern('#/thirdPartyDynamicQrGetStatus$#'), Mockery::any())
        ->andReturn($statusResponse);
    setFonepayPrivateProperty($adapter, 'httpClient', $httpClientMock);
}

beforeEach(function (): void {
    $this->adapter = new Payment_Adapter_Fonepay(fonepayConfig());

    // reconcilePending() globs every *.json under the real pending-intent directory —
    // clear it so a file a previous test deliberately left behind (e.g. the "keeps the
    // file" case below) can't leak into this test's reconcile sweep.
    $dir = invokeFonepayPrivateMethod($this->adapter, 'pendingDir', []);
    foreach (glob($dir . '/*.json') ?: [] as $leftover) {
        @unlink($leftover);
    }
});

test('refuses to credit an invoice resolved from a cold-intent callback, even with a verified matching amount', function (): void {
    // No pending-intent file was ever written for this reference in this test —
    // simulating a cold intent. $tx->invoice_id (as ipn.php would set it straight from
    // the raw, unauthenticated invoice_id query param) points at a victim invoice the
    // attacker does not own.
    $ref = 'INV1R' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    $tx = buildFonepayTransaction(900);
    $tx->invoice_id = 77;

    mockFonepayStatusCalls($this->adapter, [
        'paymentStatus' => 'success',
        'totalTransactionAmount' => 1000.00,
        'fonepayTraceId' => 'trace_attacker',
    ]);

    $dbMock = Mockery::mock('\Box_Database');
    $dbMock->shouldReceive('getExistingModelById')->with('Transaction', 900)->andReturn($tx);
    $dbMock->shouldReceive('store')->andReturn(900);

    $clientService = Mockery::mock();
    $clientService->shouldNotReceive('addFunds');

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (string $name = '', string $sub = ''): object => match ($name) {
        'client' => $clientService,
        default => Mockery::mock()->shouldIgnoreMissing(),
    });
    $this->adapter->setDi($di);

    $data = ['get' => ['reference_label' => $ref], 'post' => []];

    expect(fn (): mixed => $this->adapter->processTransaction(null, 900, $data, 5))
        ->toThrow(Payment_Exception::class, 'flagged for manual review');

    expect($tx->status)->toBe('error')
        ->and($tx->error)->toContain('SECURITY: intent file cold')
        ->and($tx->error)->toContain('Candidate invoice_id=77');
});

test('credits the invoice when the pending-intent file is present', function (): void {
    $ref = 'INV77R' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    invokeFonepayPrivateMethod($this->adapter, 'storePaymentIntent', [$ref, 77, 1000.00, 'TERM01', $ref]);

    $tx = buildFonepayTransaction(901);

    $invoice = new Model_Invoice();
    $invoice->loadBean(new DummyBean());
    $invoice->id = 77;
    $invoice->client_id = 5;
    $invoice->hash = 'abc123hash';
    $invoice->approved = 0;

    $client = new Model_Client();
    $client->loadBean(new DummyBean());
    $client->id = 5;

    mockFonepayStatusCalls($this->adapter, [
        'paymentStatus' => 'success',
        'totalTransactionAmount' => 1000.00,
        'fonepayTraceId' => 'trace_warm',
    ]);

    $dbMock = Mockery::mock('\Box_Database');
    $dbMock->shouldReceive('getExistingModelById')->with('Transaction', 901)->andReturn($tx);
    $dbMock->shouldReceive('findOne')
        ->with('Transaction', 'txn_id = ? AND status = ? AND id != ?', Mockery::any())
        ->andReturn(null);
    $dbMock->shouldReceive('getExistingModelById')->with('Invoice', 77)->andReturn($invoice);
    $dbMock->shouldReceive('getExistingModelById')->with('Client', 5)->andReturn($client);
    $dbMock->shouldReceive('store')->andReturn(1);

    $invoiceService = Mockery::mock();
    $invoiceService->shouldReceive('isInvoiceTypeDeposit')->andReturn(false);
    $invoiceService->shouldReceive('getTotalWithTax')->andReturn(1000.0);
    $invoiceService->shouldReceive('approveInvoice')->once();
    $invoiceService->shouldReceive('payInvoiceWithCredits')->once();
    $invoiceService->shouldReceive('doBatchPayWithCredits')->once();

    $clientService = Mockery::mock();
    $clientService->shouldReceive('addFunds')->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['mod_service'] = $di->protect(fn (string $name = '', string $sub = ''): object => match ($name) {
        'Invoice' => $invoiceService,
        'client' => $clientService,
        default => Mockery::mock()->shouldIgnoreMissing(),
    });
    $this->adapter->setDi($di);

    $data = ['get' => ['reference_label' => $ref], 'post' => []];

    $this->adapter->processTransaction(null, 901, $data, 5);

    expect($tx->status)->toBe('processed')
        ->and($tx->invoice_id)->toBe(77);
});

test('reconcilePending() keeps the pending-intent file when a reported success could not actually be settled', function (): void {
    // e.g. the invoice was already marked paid by another means before the cron ran —
    // settlePendingSuccess() bails out and returns false without writing a transaction
    // row. The file must survive so it isn't silently orphaned into a replayable,
    // dedup-free reference (the exact mechanism the crediting fix above closes off).
    $ref = 'INV77R' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    invokeFonepayPrivateMethod($this->adapter, 'storePaymentIntent', [$ref, 77, 1000.00, 'TERM01', $ref]);

    expect(invokeFonepayPrivateMethod($this->adapter, 'loadPaymentIntent', [$ref]))->not->toBeNull();

    mockFonepayStatusCalls($this->adapter, [
        'paymentStatus' => 'success',
        'totalTransactionAmount' => 1000.00,
        'fonepayTraceId' => 'trace_already_paid',
    ]);

    $alreadyPaidInvoice = new Model_Invoice();
    $alreadyPaidInvoice->loadBean(new DummyBean());
    $alreadyPaidInvoice->id = 77;
    $alreadyPaidInvoice->status = Model_Invoice::STATUS_PAID;

    $dbMock = Mockery::mock('\Box_Database');
    $dbMock->shouldReceive('findOne')
        ->with('Transaction', 'txn_id = ? AND status = ?', Mockery::any())
        ->andReturn(null);
    $dbMock->shouldReceive('load')->with('Invoice', 77)->andReturn($alreadyPaidInvoice);

    $di = container();
    $di['db'] = $dbMock;
    $this->adapter->setDi($di);

    $settled = $this->adapter->reconcilePending(86400);

    expect($settled)->toBe(0)
        ->and(invokeFonepayPrivateMethod($this->adapter, 'loadPaymentIntent', [$ref]))->not->toBeNull();
});

test('reconcilePending() discards an unsettled success once past the staleness cutoff', function (): void {
    $ref = 'INV77R' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    invokeFonepayPrivateMethod($this->adapter, 'storePaymentIntent', [$ref, 77, 1000.00, 'TERM01', $ref]);

    // Backdate created_at so the record is already past a 0-second maxAge.
    $file = invokeFonepayPrivateMethod($this->adapter, 'pendingFile', [$ref]);
    $record = json_decode((string) file_get_contents($file), true);
    $record['created_at'] = time() - 1000;
    file_put_contents($file, json_encode($record));

    mockFonepayStatusCalls($this->adapter, [
        'paymentStatus' => 'success',
        'totalTransactionAmount' => 1000.00,
        'fonepayTraceId' => 'trace_stale',
    ]);

    $alreadyPaidInvoice = new Model_Invoice();
    $alreadyPaidInvoice->loadBean(new DummyBean());
    $alreadyPaidInvoice->id = 77;
    $alreadyPaidInvoice->status = Model_Invoice::STATUS_PAID;

    $dbMock = Mockery::mock('\Box_Database');
    $dbMock->shouldReceive('findOne')
        ->with('Transaction', 'txn_id = ? AND status = ?', Mockery::any())
        ->andReturn(null);
    $dbMock->shouldReceive('load')->with('Invoice', 77)->andReturn($alreadyPaidInvoice);

    $di = container();
    $di['db'] = $dbMock;
    $this->adapter->setDi($di);

    $this->adapter->reconcilePending(0);

    expect(invokeFonepayPrivateMethod($this->adapter, 'loadPaymentIntent', [$ref]))->toBeNull();
});
