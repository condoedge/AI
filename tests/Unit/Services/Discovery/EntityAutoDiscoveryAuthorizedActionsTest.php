<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Discovery;

use Condoedge\Ai\Services\Actions\ModelMethodMutationAction;
use Condoedge\Ai\Services\Discovery\EntityAutoDiscovery;
use Condoedge\Ai\Tests\TestCase;

/**
 * Advertisement-time authorization filtering (90c W6).
 *
 * The response prompt used to advertise every action and rely on the renderer to
 * strip unauthorized links afterwards — the stripped offer rendered as dead text to
 * exactly the user it was stripped from. These tests pin the filtering contract:
 *
 *  - generic actions are advertised only when authorize($user, []) passes
 *    (ungated actions pass for everyone, incl. a null principal);
 *  - an authorize() that throws is NOT advertisable (fail closed);
 *  - non-mutating entity actions follow the same rule;
 *  - MUTATING entity actions always pass through: they authorize against the
 *    RESOLVED ROW, which does not exist at advertisement time — filtering them on
 *    empty input would hide them from everyone.
 */
class EntityAutoDiscoveryAuthorizedActionsTest extends TestCase
{
    private EntityAutoDiscovery $discovery;

    public function setUp(): void
    {
        parent::setUp();

        config()->set('ai.generic_actions', [
            'open_anywhere' => [
                'action' => fn ($text = 'Open') => 'link',
                'label' => 'Open anywhere',
                'aliases' => ['open'],
                // no 'authorize' key — ungated, advertisable to everyone
            ],
            'staff_only' => [
                'action' => fn ($text = 'Staff') => 'link',
                'label' => 'Staff only',
                'aliases' => [],
                'authorize' => fn ($user, array $input = []) => $user === 'staff-user',
            ],
            'broken_gate' => [
                'action' => fn ($text = 'Broken') => 'link',
                'label' => 'Broken gate',
                'aliases' => [],
                'authorize' => function ($user, array $input = []) {
                    throw new \RuntimeException('gate exploded');
                },
            ],
        ]);

        config()->set('ai.entity_actions', [
            'Thing' => [
                'view' => [
                    'action' => fn ($text = 'View') => 'link',
                    'label' => 'View thing',
                    'authorize' => fn ($user, array $input = []) => $user === 'staff-user',
                ],
                'open' => [
                    'action' => fn ($text = 'Open') => 'link',
                    'label' => 'Open thing',
                ],
                // Mutating: authorizer needs the resolved row (null at advertisement time,
                // so it would deny) — advertisement must NOT consult it.
                'approve' => new ModelMethodMutationAction(
                    key: 'approve',
                    label: 'Approve thing',
                    finder: 'App\\Models\\DoesNotMatterForAdvertisement',
                    mutator: fn ($model, array $input) => $model,
                    authorizer: fn ($user, $model, array $input) => $user !== null && $model !== null,
                ),
            ],
        ]);

        $this->discovery = app(EntityAutoDiscovery::class);
    }

    private function keys(array $actions): array
    {
        return array_column($actions, 'action_key');
    }

    /** @test */
    public function generic_actions_are_filtered_by_authorize_for_the_given_user(): void
    {
        $keys = $this->keys($this->discovery->getAuthorizedGenericActions('staff-user'));

        $this->assertContains('open_anywhere', $keys);
        $this->assertContains('staff_only', $keys);
        $this->assertNotContains('broken_gate', $keys, 'a throwing authorize() must fail closed');
    }

    /** @test */
    public function generic_actions_denied_for_the_user_are_not_advertised(): void
    {
        $keys = $this->keys($this->discovery->getAuthorizedGenericActions('board-user'));

        $this->assertContains('open_anywhere', $keys, 'ungated actions stay advertisable');
        $this->assertNotContains('staff_only', $keys, 'the renderer would strip it — do not offer it');
        $this->assertNotContains('broken_gate', $keys);
    }

    /** @test */
    public function a_null_principal_only_sees_ungated_generic_actions(): void
    {
        $keys = $this->keys($this->discovery->getAuthorizedGenericActions(null));

        // Ungated actions (incl. the package built-ins) remain; every gated one is gone.
        $this->assertContains('open_anywhere', $keys);
        $this->assertNotContains('staff_only', $keys);
        $this->assertNotContains('broken_gate', $keys);
    }

    /** @test */
    public function the_unfiltered_generic_list_is_unchanged(): void
    {
        $keys = $this->keys($this->discovery->getGenericActions());

        $this->assertContains('open_anywhere', $keys);
        $this->assertContains('staff_only', $keys);
        $this->assertContains('broken_gate', $keys);
    }

    /** @test */
    public function non_mutating_entity_actions_are_filtered_but_mutating_ones_pass_through(): void
    {
        $keys = $this->keys($this->discovery->getAuthorizedEntityActions('Thing', 'board-user'));

        $this->assertContains('open', $keys, 'ungated non-mutating action stays');
        $this->assertNotContains('view', $keys, 'denied non-mutating action is not advertised');
        $this->assertContains('approve', $keys, 'mutating actions keep their render+approval-time gates');
    }

    /** @test */
    public function authorized_entity_actions_include_gated_ones_for_an_allowed_user(): void
    {
        $keys = $this->keys($this->discovery->getAuthorizedEntityActions('Thing', 'staff-user'));

        $this->assertContains('view', $keys);
        $this->assertContains('open', $keys);
        $this->assertContains('approve', $keys);
    }
}
