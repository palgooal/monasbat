<?php
if (!defined('ABSPATH')) exit;

/**
 * Local/test-only Cartat substitute for Manual Thank You verification.
 *
 * It deliberately stores only an in-process send counter. Phone numbers,
 * message content, credentials, and transport responses are never persisted
 * or logged. Every delivery method is overridden, so this class has no HTTP
 * capability even though it preserves the existing Cartat type contract.
 */
class PGE_Thank_You_Test_Transport extends PGE_Cartat_Transport
{
    private string $outcome;
    private static int $send_count = 0;

    public function __construct()
    {
        // Reuse the production number-formatting contract. Credentials may be
        // read by the parent but are never used, exposed, logged, or persisted.
        parent::__construct();

        $outcome = defined('PGE_TEST_TRANSPORT_OUTCOME')
            ? strtolower(trim((string) PGE_TEST_TRANSPORT_OUTCOME))
            : 'accepted';
        $this->outcome = in_array($outcome, ['accepted', 'rejected', 'ambiguous'], true)
            ? $outcome
            : 'accepted';
    }

    public function has_credentials(): bool
    {
        return true;
    }

    public function send_text(string $number, string $message): ?array
    {
        self::$send_count++;
        return $this->simulated_result();
    }

    public function send_media(string $number, string $media_url, string $caption = ''): ?array
    {
        self::$send_count++;
        return $this->simulated_result();
    }

    public static function send_count(): int
    {
        return self::$send_count;
    }

    private function simulated_result(): ?array
    {
        if ($this->outcome === 'ambiguous') {
            return null;
        }
        if ($this->outcome === 'rejected') {
            return ['status' => 'error'];
        }
        return ['status' => 'sent'];
    }
}

/** Server-authoritative transport selection for Manual Thank You only. */
class PGE_Thank_You_Transport_Factory
{
    public static function resolve(): PGE_Cartat_Transport
    {
        if (self::test_transport_is_allowed()) {
            return new PGE_Thank_You_Test_Transport();
        }

        return new PGE_Cartat_Transport();
    }

    private static function test_transport_is_allowed(): bool
    {
        if (!defined('PGE_ENABLE_TEST_TRANSPORT') || PGE_ENABLE_TEST_TRANSPORT !== true) {
            return false;
        }

        if (!function_exists('wp_get_environment_type')) {
            return false;
        }

        return in_array(wp_get_environment_type(), ['local', 'test'], true);
    }
}
