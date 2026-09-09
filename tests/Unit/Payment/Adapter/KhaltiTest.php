<?php

declare(strict_types=1);

/**
 * Regression coverage for the cold-intent invoice-binding fix (2026-09-09).
 *
 * processTransaction() must never credit an invoice whose id was resolved from the
 * browser-supplied purchase_order_id / invoice_id callback params (the "cold intent"
 * fallback) — only from the server-written intent snapshot written at initiation.
 * Before the fix, a genuine Khalti-Lookup-verified payment for a self-controlled small
 * invoice could be replayed once the intent cache expired to credit an arbitrary invoice.
 */

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tests\Helpers\DummyBean;

use function Tests\Helpers\container;

function buildKhaltiTransaction(int $id): Model_Transaction
{
    $tx = new Model_Transaction();
    $tx->loadBean(new DummyBean());
    $tx->id = $id;

    return $tx;
}

function setKhaltiPrivateProperty(object $obj, string $property, mixed $value): void
{
    $reflection = new ReflectionClass($obj);
    $prop = $reflection->getProperty($property);
    $prop->setValue($obj, $value);
}

function invokeKhaltiPrivateMethod(object $obj, string $method, array $args = []): mixed
{
    $reflection = new ReflectionClass($obj);
    $methodObj = $reflection->getMethod($method);

    return $methodObj->invokeArgs($obj, $args);
}

function mockKhaltiLookupResponse(array $body): ResponseInterface
{
    $response = Mockery::mock(ResponseInterface::class);
    $response->shouldReceive('getStatusCode')->andReturn(200);
    $response->shouldReceive('getContent')->with(false)->andReturn(json_encode($body));

    return $response;
}

beforeEach(function (): void {
    $this->adapter = new Payment_Adapter_Khalti([
        'test_mode' => true,
        'test_secret_key' => 'sk_test_dummy',
        'gateway_id' => 4,
    ]);
});

test('refuses to credit an invoice resolved from a cold-intent callback, even with a Lookup-verified matching amount', function (): void {
    // Attacker's own real pidx (Khalti genuinely reports it Completed), but no intent
    // snapshot was ever written server-side for this pidx in this test — simulating a
    // cold/expired intent. The attacker supplies purchase_order_id for SOMEONE ELSE's
    // invoice (serie A, nr 1) on the callback.
    $pidx = 'pidx_cold_' . uniqid();
    $tx = buildKhaltiTransaction(900);

    $targetInvoice = new Model_Invoice();
    $targetInvoice->loadBean(new DummyBean());
    $targetInvoice->id = 77;
    $targetInvoice->serie = 'A';
    $targetInvoice->nr = 1;

    $httpClientMock = Mockery::mock(HttpClientInterface::class);
    $httpClientMock->shouldReceive('request')
        ->once()
        ->with('POST', Mockery::pattern('#/epayment/lookup/$#'), Mockery::any())
        ->andReturn(mockKhaltiLookupResponse([
            'status' => 'Completed',
            'total_amount' => 100000,
            'transaction_id' => 'khalti_txn_real',
            'fee' => 0,
            'refunded' => false,
        ]));
    setKhaltiPrivateProperty($this->adapter, 'httpClient', $httpClientMock);

    $dbMock = Mockery::mock('\Box_Database');
    $dbMock->shouldReceive('getExistingModelById')->with('Transaction', 900)->andReturn($tx);
    $dbMock->shouldReceive('findOne')
        ->with('Invoice', 'serie = ? AND nr = ?', ['A', 1])
        ->andReturn($targetInvoice);
    $dbMock->shouldReceive('findOne')
        ->with('Transaction', 'txn_id = ? AND status = ? AND id != ?', ['khalti_txn_real', 'processed', 900])
        ->andReturn(null);
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

    $data = [
        'get' => [
            'pidx' => $pidx,
            'purchase_order_id' => 'INV-A00001',
        ],
        'post' => [],
    ];

    expect(fn (): mixed => $this->adapter->processTransaction(null, 900, $data, 4))
        ->toThrow(Payment_Exception::class, 'flagged for manual review');

    expect($tx->status)->toBe('error')
        ->and($tx->error)->toContain('SECURITY: intent snapshot cold')
        ->and($tx->error)->toContain('Candidate invoice_id=77');
});

test('credits the invoice when the intent snapshot is warm', function (): void {
    $pidx = 'pidx_warm_' . uniqid();
    invokeKhaltiPrivateMethod($this->adapter, 'storePaymentIntent', [$pidx, 77, 100000, 'INV-A00001', 3600]);

    $tx = buildKhaltiTransaction(901);

    $invoice = new Model_Invoice();
    $invoice->loadBean(new DummyBean());
    $invoice->id = 77;
    $invoice->serie = 'A';
    $invoice->nr = 1;
    $invoice->client_id = 5;
    $invoice->hash = 'abc123hash';
    $invoice->approved = 0;

    $client = new Model_Client();
    $client->loadBean(new DummyBean());
    $client->id = 5;

    $httpClientMock = Mockery::mock(HttpClientInterface::class);
    $httpClientMock->shouldReceive('request')
        ->once()
        ->with('POST', Mockery::pattern('#/epayment/lookup/$#'), Mockery::any())
        ->andReturn(mockKhaltiLookupResponse([
            'status' => 'Completed',
            'total_amount' => 100000,
            'transaction_id' => 'khalti_txn_warm',
            'fee' => 0,
            'refunded' => false,
        ]));
    setKhaltiPrivateProperty($this->adapter, 'httpClient', $httpClientMock);

    $dbMock = Mockery::mock('\Box_Database');
    $dbMock->shouldReceive('getExistingModelById')->with('Transaction', 901)->andReturn($tx);
    $dbMock->shouldReceive('findOne')
        ->with('Transaction', 'txn_id = ? AND status = ? AND id != ?', Mockery::any())
        ->andReturn(null);
    $dbMock->shouldReceive('load')->with('Invoice', 77)->andReturn($invoice);
    $dbMock->shouldReceive('getExistingModelById')->with('Invoice', 77)->andReturn($invoice);
    $dbMock->shouldReceive('getExistingModelById')->with('Client', 5)->andReturn($client);
    $dbMock->shouldReceive('store')->andReturn(1);

    $dbalMock = Mockery::mock();
    $dbalMock->shouldReceive('fetchOne')->with('SELECT GET_LOCK(:l, 10)', Mockery::any())->andReturn(1);
    $dbalMock->shouldReceive('fetchOne')->with('SELECT RELEASE_LOCK(:l)', Mockery::any())->andReturn(1);

    $transactionService = Mockery::mock();
    $transactionService->shouldReceive('claimForProcessing')->once()->andReturn(true);

    $invoiceService = Mockery::mock();
    $invoiceService->shouldReceive('validatePaymentAmount')->once()->andReturn(null);
    $invoiceService->shouldReceive('isInvoiceTypeDeposit')->andReturn(false);
    $invoiceService->shouldReceive('getTotalWithTax')->andReturn(1000.0);
    $invoiceService->shouldReceive('approveInvoice')->once();
    $invoiceService->shouldReceive('payInvoiceWithCredits')->once();
    $invoiceService->shouldReceive('doBatchPayWithCredits')->once();

    $clientService = Mockery::mock();
    $clientService->shouldReceive('addFunds')->once();

    $di = container();
    $di['db'] = $dbMock;
    $di['dbal'] = $dbalMock;
    $di['mod_service'] = $di->protect(fn (string $name = '', string $sub = ''): object => match (true) {
        $name === 'Invoice' && $sub === 'Transaction' => $transactionService,
        $name === 'Invoice' => $invoiceService,
        $name === 'client' => $clientService,
        default => Mockery::mock()->shouldIgnoreMissing(),
    });
    $this->adapter->setDi($di);

    $data = ['get' => ['pidx' => $pidx], 'post' => []];

    $this->adapter->processTransaction(null, 901, $data, 4);

    expect($tx->status)->toBe('processed')
        ->and($tx->invoice_id)->toBe(77);
});
