<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit;

use Condoedge\Ai\Kompo\Actions\ApprovalCard;
use Condoedge\Ai\Tests\TestCase;

/**
 * The approval-card buttons label themselves with `__('ai.actions.confirm')` etc. For a MISSING
 * JSON key Laravel returns the key string itself (truthy), so the `?:` fallback never fires and the
 * UI renders the raw key (e.g. "ai.actions.confirm"). These tests pin the contract that the
 * ai.actions.* keys actually resolve to localized text and that the rendered card reflects that.
 */
class ActionI18nKeysTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function actionKeyProvider(): array
    {
        return [
            'confirm' => ['ai.actions.confirm'],
            'cancel' => ['ai.actions.cancel'],
            'failed' => ['ai.actions.failed'],
        ];
    }

    /**
     * @dataProvider actionKeyProvider
     */
    public function test_action_key_resolves_to_localized_text_in_english(string $key): void
    {
        app()->setLocale('en');

        $value = trans($key);

        $this->assertNotSame($key, $value, "Missing JSON key '{$key}' falls through to the raw key string.");
        $this->assertNotEmpty($value);
    }

    /**
     * @dataProvider actionKeyProvider
     */
    public function test_action_key_resolves_to_localized_text_in_french(string $key): void
    {
        app()->setLocale('fr');

        $value = trans($key);

        $this->assertNotSame($key, $value, "Missing JSON key '{$key}' falls through to the raw key string.");
        $this->assertNotEmpty($value);
    }

    public function test_rendered_approval_card_buttons_are_not_raw_keys(): void
    {
        app()->setLocale('en');

        // A pass-through wiring keeps the assertion on the label text only, without invoking the
        // default selfPost round-trip (whose token signing is irrelevant to this i18n contract).
        $card = ApprovalCard::make([
            'token' => 'tok-123',
            'label' => 'Delete record',
            'confirm_message' => 'Delete this record?',
        ], fn ($link) => $link);

        // With Kompo helpers booted, make() returns a _Rows layout, not the structured payload.
        $this->assertNotIsArrayPayload($card);

        [$confirm, $cancel] = $this->extractActionButtons($card);

        $this->assertSame(trans('ai.actions.confirm'), $confirm->label);
        $this->assertSame(trans('ai.actions.cancel'), $cancel->label);
        $this->assertNotSame('ai.actions.confirm', $confirm->label);
        $this->assertNotSame('ai.actions.cancel', $cancel->label);
    }

    private function assertNotIsArrayPayload(mixed $card): void
    {
        $this->assertFalse(
            is_array($card),
            'Kompo helpers must be booted so the card renders real link elements (not the offline array payload).'
        );
    }

    /**
     * The card is _Rows(_Flex(message), _Flex($confirm, $cancel), _Panel()). Pull the two action
     * links from the second row.
     *
     * @return array{0: object, 1: object}
     */
    private function extractActionButtons(object $card): array
    {
        $buttonRow = $card->elements[1];

        return [$buttonRow->elements[0], $buttonRow->elements[1]];
    }
}
