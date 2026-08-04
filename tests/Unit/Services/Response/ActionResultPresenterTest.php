<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Response;

use Condoedge\Ai\Services\Actions\ActionDispatcher;
use Condoedge\Ai\Services\Actions\AiActionRegistry;
use Condoedge\Ai\Services\Actions\Results\AiActionResult;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Services\Response\ActionLinkHandler;
use Condoedge\Ai\Services\Response\ActionResultPresenter;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\CapturingAction;
use Condoedge\Ai\Tests\Unit\Services\Actions\Stubs\MutatingSpyAction;
use Orchestra\Testbench\TestCase;
use Mockery;

/**
 * ActionResultPresenter — the ONE presentation seam for AiActionResults, extracted from
 * ActionLinkHandler::renderResult() so both transports (parsed markdown links AND persisted
 * native-turn invocations) render through identical lines:
 *
 *   - Pending  → ApprovalCard (the non-Kompo array form in this unit context),
 *   - export errors → visible honest-failure notice (never a silently inert link),
 *   - other errors  → fail closed (nothing) — unless flagged as a persisted MUTATING
 *     invocation, where a neutral "no longer available" notice replaces the silence,
 *   - plain renderables pass straight through.
 *
 * The link handler keeps a one-line delegation; the parity tests below drive the SAME
 * results through both the handler path and the presenter directly.
 */
class ActionResultPresenterTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function presenter(): ActionResultPresenter
    {
        return new ActionResultPresenter();
    }

    private function handler(AiActionRegistry $registry): ActionLinkHandler
    {
        $discovery = Mockery::mock(EntityAutoDiscovery::class);
        return new ActionLinkHandler($discovery, new ActionDispatcher($registry));
    }

    /** @test */
    public function a_null_result_presents_nothing(): void
    {
        $this->assertNull($this->presenter()->present(null));
        $this->assertNull($this->presenter()->present(null, 'export_excel'));
    }

    /** @test */
    public function element_results_pass_straight_through(): void
    {
        $payload = ['route' => 'units.show', 'id' => 402];

        $this->assertSame(
            $payload,
            $this->presenter()->present(AiActionResult::element($payload, 'Open unit'))
        );
    }

    /** @test */
    public function table_results_keep_the_type_tagged_legacy_shape(): void
    {
        $table = ['headers' => ['Name'], 'rows' => [['Acme']]];

        $this->assertSame(
            ['type' => 'table', 'headers' => ['Name'], 'rows' => [['Acme']]],
            $this->presenter()->present(AiActionResult::table($table, '1 row'))
        );
    }

    /** @test */
    public function pending_results_render_the_approval_card_array_form(): void
    {
        $result = AiActionResult::pending(
            'tok123',
            'This will archive. Confirm?',
            'Proposed (requires approval): Archive',
            ['action_key' => 'archive', 'label' => 'Archive', 'text' => 'Archive now'],
        );

        $this->assertSame(
            [
                'type' => 'approval_card',
                'token' => 'tok123',
                'label' => 'Archive',
                'message' => 'This will archive. Confirm?',
            ],
            $this->presenter()->present($result, 'archive')
        );
    }

    /** @test */
    public function non_export_errors_fail_closed_to_nothing(): void
    {
        $this->assertNull(
            $this->presenter()->present(AiActionResult::error('internal reason'), 'charge')
        );
    }

    /** @test */
    public function export_errors_surface_the_honest_reason(): void
    {
        $presented = $this->presenter()->present(
            AiActionResult::error('Excel export requires the maatwebsite/excel package.'),
            'export_excel'
        );

        $this->assertStringContainsString('maatwebsite/excel', $this->renderToString($presented));
    }

    /** @test */
    public function export_successes_present_json_safe_download_payloads_in_unit_context(): void
    {
        $presented = $this->presenter()->present(
            AiActionResult::export("%PDF-1.4\n" . chr(128), 'application/pdf', 'profile.pdf'),
            'export_pdf'
        );

        if (is_array($presented)) {
            $this->assertSame('download', $presented['type']);
            $this->assertSame('application/pdf', $presented['mime']);
            $this->assertSame('profile.pdf', $presented['filename']);
            $this->assertStringStartsWith('data:application/pdf;base64,', $presented['href']);
            $this->assertArrayNotHasKey('content', $presented);
            $this->assertIsString(json_encode($presented, JSON_THROW_ON_ERROR));
        } else {
            $this->assertIsObject($presented);
            $this->assertSame('profile.pdf', $presented->label);
            $this->assertStringStartsWith('data:application/pdf;base64,', $presented->href);
            $this->assertSame('profile.pdf', $presented->config['attrs']['download'] ?? null);
        }
    }

    /** @test */
    public function an_empty_export_error_summary_falls_back_to_a_default_message(): void
    {
        $presented = $this->presenter()->present(AiActionResult::error(''), 'export_pdf');

        $this->assertStringContainsString('unavailable', $this->renderToString($presented));
    }

    /** @test */
    public function a_denied_mutating_invocation_renders_the_unavailable_notice_instead_of_silence(): void
    {
        $presented = $this->presenter()->present(
            AiActionResult::error('You are not authorized to perform this action.'),
            'archive',
            null,
            true,
        );

        $this->assertStringContainsString('no longer available', $this->renderToString($presented));
    }

    /** @test */
    public function the_unavailable_notice_never_leaks_the_internal_error_reason(): void
    {
        $presented = $this->presenter()->present(
            AiActionResult::error('SQLSTATE[42S02] secret internals'),
            'archive',
            null,
            true,
        );

        $rendered = $this->renderToString($presented);
        $this->assertStringNotContainsString('SQLSTATE', $rendered);
        $this->assertStringNotContainsString('secret', $rendered);
    }

    /** @test */
    public function the_link_handler_delegates_element_results_to_the_presenter(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction('settings', fn () => 'settings-link'));

        $viaHandler = $this->handler($registry)->createElements('[Settings](action://settings)');

        $viaPresenter = $this->presenter()->present(
            AiActionResult::element('settings-link', 'Settings'),
            'settings'
        );

        $this->assertSame([$viaPresenter], $viaHandler);
    }

    /** @test */
    public function the_link_handler_delegates_export_failures_to_the_presenter(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new CapturingAction(
            'export_excel',
            fn () => AiActionResult::error('Excel export requires the maatwebsite/excel package.'),
        ));

        $viaHandler = $this->handler($registry)->createElements('[Download](action://export_excel)');

        $viaPresenter = $this->presenter()->present(
            AiActionResult::error('Excel export requires the maatwebsite/excel package.'),
            'export_excel'
        );

        $this->assertStringContainsString('maatwebsite/excel', $this->renderToString($viaHandler[0] ?? null));
        $this->assertStringContainsString('maatwebsite/excel', $this->renderToString($viaPresenter));
    }

    /** @test */
    public function the_link_handler_renders_pending_results_as_approval_cards_through_the_presenter(): void
    {
        $registry = new AiActionRegistry();
        $registry->registerGeneric(new MutatingSpyAction(
            'archive',
            authorized: true,
            onHandle: function (): void {
                throw new \LogicException('a mutating action must never execute at render');
            },
        ));

        $elements = $this->handler($registry)->createElements('[Archive](action://archive)');

        $this->assertCount(1, $elements);
        $card = $elements[0];
        $this->assertIsArray($card);
        $this->assertSame('approval_card', $card['type']);
        $this->assertSame('Archive', $card['label']);
        $this->assertSame('This will archive. Confirm?', $card['message']);
        $this->assertNotSame('', $card['token']);
    }

    private function renderToString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if (is_object($value)) {
            $parts = [];
            foreach (['label', 'href', 'class'] as $property) {
                if (property_exists($value, $property) && is_scalar($value->{$property})) {
                    $parts[] = (string) $value->{$property};
                }
            }

            return implode(' ', $parts) . ' ' . json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return (string) $value;
    }
}
