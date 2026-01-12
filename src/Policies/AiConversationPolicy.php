<?php

declare(strict_types=1);

namespace Condoedge\Ai\Policies;

use Condoedge\Ai\Models\AiConversation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Access\HandlesAuthorization;

class AiConversationPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view the conversation
     *
     * @param Authenticatable $user
     * @param AiConversation $conversation
     * @return bool
     */
    public function view(Authenticatable $user, AiConversation $conversation): bool
    {
        // Owner can always view
        if ($conversation->user_id === $user->id) {
            return true;
        }

        // Team member can view team conversations
        if ($conversation->team_id && $this->userBelongsToTeam($user, $conversation->team_id)) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the user can send messages to the conversation
     *
     * @param Authenticatable $user
     * @param AiConversation $conversation
     * @return bool
     */
    public function sendMessage(Authenticatable $user, AiConversation $conversation): bool
    {
        // Same as view for now - owner or team member
        return $this->view($user, $conversation);
    }

    /**
     * Determine if the user can delete the conversation
     *
     * @param Authenticatable $user
     * @param AiConversation $conversation
     * @return bool
     */
    public function delete(Authenticatable $user, AiConversation $conversation): bool
    {
        // Only owner can delete
        return $conversation->user_id === $user->id;
    }

    /**
     * Check if user belongs to a team
     *
     * @param Authenticatable $user
     * @param int $teamId
     * @return bool
     */
    protected function userBelongsToTeam(Authenticatable $user, int $teamId): bool
    {
        if (method_exists($user, 'belongsToTeam')) {
            return $user->belongsToTeam($teamId);
        }

        if (method_exists($user, 'teams')) {
            return $user->teams()->where('id', $teamId)->exists();
        }

        if (method_exists($user, 'currentTeamId')) {
            return $user->currentTeamId() === $teamId;
        }

        return false;
    }
}
