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
 *  - MUTATING entity actions are advertised ONLY to principals passing their
 *    row-independent capability (`permission:`) — security A1/A2: they used to pass
 *    through unfiltered and were offered to every user (a resident saw "approve
 *    reservation"). Their render + approval-time row gates still run on top.
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
                // Mutating: the row authorizer needs the resolved row (null at advertisement
                // time) — advertisement consults the row-independent `permission` instead.
                'approve' => new ModelMethodMutationAction(
                    key: 'approve',
                    label: 'Approve thing',
                    finder: 'App\\Models\\DoesNotMatterForAdvertisement',
                    mutator: fn ($model, array $input) => $model,
                    authorizer: fn ($user, $model, array $input) => $user !== null && $model !== null,
                    permission: fn ($user) => $user === 'staff-user',
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
    public function entity_actions_including_mutations_are_filtered_for_a_user_without_the_capability(): void
    {
        $keys = $this->keys($this->discovery->getAuthorizedEntityActions('Thing', 'board-user'));

        $this->assertContains('open', $keys, 'ungated non-mutating action stays');
        $this->assertNotContains('view', $keys, 'denied non-mutating action is not advertised');
        $this->assertNotContains('approve', $keys, 'a mutation is never offered to a user lacking its capability (A1/A2)');
    }

    /** @test */
    public function a_null_principal_is_never_offered_a_mutation(): void
    {
        $this->assertNotContains('approve', $this->keys($this->discovery->getAuthorizedEntityActions('Thing', null)));
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
