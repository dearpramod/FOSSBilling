<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */
require_once __DIR__ . DIRECTORY_SEPARATOR . 'load.php';

use FOSSBilling\Http\ApiResponseFactory;
use FOSSBilling\Http\ResponseFactory;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\JsonResponse;

/* @var Symfony\Component\HttpFoundation\Request $request */
global $request;

// A gateway may HTML-encode its redirect query string (seen on Khalti connectIPS
// cancel), or the server may run with arg_separator.output='&amp;'. Either way PHP
// then keys every param after the first as "amp;invoice_id" etc., so invoice_id,
// redirect and pidx are lost downstream, producing "Transaction invoice ID is
// missing" plus a raw JSON page instead of a redirect. Normalize the query string
// before anything reads it. Guarded on the &amp; marker so normal callbacks/JSON
// webhooks are left untouched.
if ($request instanceof Symfony\Component\HttpFoundation\Request) {
    $rawQueryString = (string) $request->server->get('QUERY_STRING');
    if (str_contains($rawQueryString, '&amp;')) {
        parse_str(str_replace('&amp;', '&', $rawQueryString), $normalizedQueryParams);
        $request->query->replace($normalizedQueryParams);
        $_GET = $normalizedQueryParams;
    }
}

$di = include Path::join(PATH_ROOT, 'di.php');
$di['translate']();
$apiResponseFactory = new ApiResponseFactory();

$invoiceID = $request->get('invoice_id');
if ($invoiceID !== null) {
    $invoiceID = filter_var($invoiceID, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($invoiceID === false) {
        emitResponse(new JsonResponse(['result' => null, 'error' => ['message' => 'Invalid invoice ID']], 400));
    }
}

$gatewayID = $request->get('gateway_id');

if ($gatewayID !== null) {
    $gatewayID = filter_var($gatewayID, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($gatewayID === false) {
        emitResponse(new JsonResponse(['result' => null, 'error' => ['message' => 'Invalid gateway ID']], 400));
    }
}

$rawBody = $request->getContent();

$ipn = [
    'invoice_id' => $invoiceID,
    'gateway_id' => $gatewayID,
    'source' => 'ipn',
    'get' => $request->query->all(),
    'post' => $request->request->all(),
    'server' => $request->server->all(),
    'http_raw_post_data' => $rawBody,
];

$contentType = $request->headers->get('Content-Type', '');
$isJsonWebhook = str_contains((string) $contentType, 'application/json') && !empty($rawBody);
if ($isJsonWebhook) {
    $ipn['skip_validation'] = true;
}

try {
    $service = $di['mod_service']('invoice', 'transaction');

    // JSON webhooks (Stripe, etc.) require fast 2xx acknowledgment.
    // When running under FastCGI, decouple the HTTP response from processing:
    // create the transaction, send 200, then finish in the background via
    // fastcgi_finish_request().
    if ($isJsonWebhook && function_exists('fastcgi_finish_request')) {
        $transactionId = $service->create($ipn);
        $response = $apiResponseFactory->create($transactionId);
        sendResponse($response);
        fastcgi_finish_request();

        // Process in the background; errors are logged on the transaction.
        $service->processAndCatchErrors((int) $transactionId);

        return;
    }

    $output = $service->createAndProcess($ipn);
    $response = $apiResponseFactory->create($output);
} catch (Exception $e) {
    $response = $apiResponseFactory->create(null, $e);
}

// redirect to invoice if gateway requires
// Khalti sets invoice_hash on $di['request']->query inside processTransaction(),
// so a plain query->get() is sufficient after processTransaction() returns.
$resolvedInvoiceHash = $request->query->get('invoice_hash');
if ($request->query->has('redirect') && $resolvedInvoiceHash !== null) {
    $invoiceHash = $resolvedInvoiceHash;
    $hash = preg_replace('/[^a-zA-Z0-9]/', '', is_string($invoiceHash) ? $invoiceHash : '');

    // Forward restore_token so the client portal can restore the session
    // (the token was signed at checkout time and placed in the return_url).
    $redirectParams = [];
    $rawRestoreToken = $request->query->get('restore_token');
    if (is_string($rawRestoreToken) && $rawRestoreToken !== '') {
        $redirectParams['restore_token'] = $rawRestoreToken;
    }

    // Surface a status hint so the invoice template can show cancel/error feedback.
    // 'ok' only when the transaction reached 'processed' status; 'cancel' otherwise.
    // Checking the actual transaction record is necessary because createAndProcess()
    // always returns the transaction ID (truthy) even when the payment was cancelled
    // or failed — the $output alone cannot distinguish success from failure.
    $txProcessed = false;
    if (isset($output) && is_int($output) && $output > 0) {
        try {
            $txRecord = $di['db']->load('Transaction', $output);
            $txProcessed = $txRecord && $txRecord->status === 'processed';
        } catch (Throwable) {
        }
    }
    $redirectParams['status'] = $txProcessed ? 'ok' : 'cancel';

    $url = $di['url']->link('invoice/' . $hash, $redirectParams);
    emitResponse((new ResponseFactory())->redirect($url));
}

emitResponse($response);
