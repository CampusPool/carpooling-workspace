<?php

namespace STS\Mobility\Providers;

use JsonException;
use STS\Mobility\Payments\PaymentException;

/** Verifies a captured UPI webhook; deliberately has no public route or network client. */
final class RazorpayCaptureVerifier
{
    public function __construct(
        private readonly string $webhookSecret,
        private readonly string $environment,
        private readonly string $accountReference,
    ) {
        if ($webhookSecret === '' || ! in_array($environment, ['test', 'live'], true)
            || ! preg_match('/^acc_[A-Za-z0-9]{1,156}$/D', $accountReference)) {
            throw new PaymentException('Invalid webhook verifier configuration.', 'PROVIDER_CONFIGURATION_INVALID');
        }
    }

    public function verify(string $rawBody, string $signature, string $eventId): array
    {
        if (strlen($rawBody) > 1048576 || ! preg_match('/^[a-f0-9]{64}$/D', $signature)
            || ! hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $signature)) {
            throw new PaymentException('Webhook signature is invalid.', 'WEBHOOK_SIGNATURE_INVALID');
        }
        if (! preg_match('/^[\x21-\x7E]{1,160}$/D', $eventId)) {
            throw new PaymentException('Webhook event identity is invalid.', 'WEBHOOK_EVENT_INVALID');
        }
        try {
            $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new PaymentException('Webhook body is invalid JSON.', 'WEBHOOK_PAYLOAD_INVALID');
        }
        if (! is_array($payload)) {
            throw new PaymentException('Webhook body must be an event object.', 'WEBHOOK_CAPTURE_INVALID');
        }
        $payment = $payload['payload']['payment']['entity'] ?? null;
        if (($payload['entity'] ?? null) !== 'event'
            || ($payload['account_id'] ?? null) !== $this->accountReference
            || ($payload['event'] ?? null) !== 'payment.captured' || ! is_array($payment)
            || ($payment['entity'] ?? null) !== 'payment' || ($payment['status'] ?? null) !== 'captured'
            || ($payment['captured'] ?? null) !== true || ($payment['currency'] ?? null) !== 'INR'
            || ($payment['method'] ?? null) !== 'upi'
            || ! is_string($payment['id'] ?? null) || ! preg_match('/^pay_[A-Za-z0-9]{1,156}$/D', $payment['id'])
            || ! is_string($payment['order_id'] ?? null) || ! preg_match('/^order_[A-Za-z0-9]{1,154}$/D', $payment['order_id'])
            || ! is_int($payment['amount'] ?? null) || $payment['amount'] <= 0
            || $payment['amount'] > intdiv(PHP_INT_MAX, 4)) {
            throw new PaymentException('Webhook is not an accepted account UPI capture.', 'WEBHOOK_CAPTURE_INVALID');
        }
        if (($payment['amount_refunded'] ?? null) !== 0 || ($payment['amount_transferred'] ?? null) !== 0) {
            throw new PaymentException('Payment has previous financial movements; reconcile first.', 'CAPTURE_RECONCILIATION_REQUIRED');
        }

        return [
            'provider' => 'razorpay', 'environment' => $this->environment, 'event_id' => $eventId,
            'payment_reference' => $payment['id'], 'order_reference' => $payment['order_id'],
            'status' => 'captured', 'amount' => $payment['amount'], 'currency' => 'INR',
        ];
    }
}
