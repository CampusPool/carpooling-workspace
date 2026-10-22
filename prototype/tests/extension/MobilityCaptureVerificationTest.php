<?php

namespace Tests\Feature\Mobility;

use STS\Mobility\Payments\PaymentException;
use STS\Mobility\Providers\RazorpayCaptureVerifier;
use Tests\TestCase;

class MobilityCaptureVerificationTest extends TestCase
{
    private const SECRET = 'synthetic-webhook-secret-not-a-live-key';

    public function test_valid_signed_capture_maps_only_financial_fields(): void
    {
        $raw = $this->body();
        $fact = $this->verifier()->verify($raw, $this->signature($raw), 'evt_test');
        $this->assertSame(['provider' => 'razorpay', 'environment' => 'test', 'event_id' => 'evt_test',
            'payment_reference' => 'pay_Synthetic', 'order_reference' => 'order_Synthetic',
            'status' => 'captured', 'amount' => 15000, 'currency' => 'INR'], $fact);
        $this->assertArrayNotHasKey('email', $fact);
    }

    public function test_wrong_secret_and_modified_raw_body_are_rejected(): void
    {
        $raw = $this->body();
        foreach ([hash_hmac('sha256', $raw, 'other-secret'), str_repeat('0', 64)] as $signature) {
            $this->reject($raw, $signature, 'WEBHOOK_SIGNATURE_INVALID');
        }
        $this->reject($raw."\n", $this->signature($raw), 'WEBHOOK_SIGNATURE_INVALID');
    }

    public function test_wrong_account_authorization_and_client_success_are_not_capture_proof(): void
    {
        foreach ([['account_id' => 'acc_Other'], ['event' => 'payment.authorized'], ['event' => 'checkout.success']] as $overrides) {
            $raw = $this->body([], $overrides);
            $this->reject($raw, $this->signature($raw), 'WEBHOOK_CAPTURE_INVALID');
        }
        foreach ([['captured' => false], ['status' => 'authorized'], ['method' => 'cod'], ['currency' => 'USD'],
            ['order_id' => null], ['id' => ''], ['amount' => 15000.9], ['amount' => '15000'], ['amount' => true], ['amount' => -1]] as $overrides) {
            $raw = $this->body($overrides);
            $this->reject($raw, $this->signature($raw), 'WEBHOOK_CAPTURE_INVALID');
        }
    }

    public function test_previous_refund_or_transfer_requires_reconciliation(): void
    {
        foreach ([['amount_refunded' => 100], ['amount_transferred' => 100]] as $overrides) {
            $raw = $this->body($overrides);
            $this->reject($raw, $this->signature($raw), 'CAPTURE_RECONCILIATION_REQUIRED');
        }
    }

    public function test_signed_malformed_payload_and_missing_event_identity_are_rejected(): void
    {
        foreach (['{', 'null', '[]'] as $raw) {
            try {
                $this->verifier()->verify($raw, $this->signature($raw), 'evt_test');
                $this->fail('Malformed payload accepted');
            } catch (PaymentException $e) {
                $this->assertContains($e->codeName, ['WEBHOOK_PAYLOAD_INVALID', 'WEBHOOK_CAPTURE_INVALID']);
            }
        }
        $raw = $this->body();
        $this->expectException(PaymentException::class);
        $this->verifier()->verify($raw, $this->signature($raw), '');
    }

    public function test_environment_is_selected_by_secret_configuration_not_payload(): void
    {
        $raw = $this->body([], ['environment' => 'live']);
        $fact = $this->verifier()->verify($raw, $this->signature($raw), 'evt_test');
        $this->assertSame('test', $fact['environment']);
    }

    private function verifier(): RazorpayCaptureVerifier
    {
        return new RazorpayCaptureVerifier(self::SECRET, 'test', 'acc_Synthetic');
    }

    private function body(array $payment = [], array $event = []): string
    {
        return json_encode(array_replace(['entity' => 'event', 'account_id' => 'acc_Synthetic', 'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => array_replace(['entity' => 'payment', 'id' => 'pay_Synthetic',
                'order_id' => 'order_Synthetic', 'amount' => 15000, 'currency' => 'INR', 'status' => 'captured',
                'captured' => true, 'method' => 'upi', 'amount_refunded' => 0, 'amount_transferred' => 0,
                'email' => 'synthetic@example.test'], $payment)]]], $event), JSON_THROW_ON_ERROR);
    }

    private function signature(string $raw): string
    {
        return hash_hmac('sha256', $raw, self::SECRET);
    }

    private function reject(string $raw, string $signature, string $code): void
    {
        try {
            $this->verifier()->verify($raw, $signature, 'evt_test');
            $this->fail('Invalid capture accepted');
        } catch (PaymentException $e) {
            $this->assertSame($code, $e->codeName);
        }
    }
}
