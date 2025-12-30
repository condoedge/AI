<?php

namespace Condoedge\Ai\Kompo\Traits;

trait HasAvatars
{
    protected function userAvatarHtml(): string
    {
        $user = auth()->user();
        $initial = strtoupper(substr($user?->name ?? 'U', 0, 1));
        $initial = htmlspecialchars($initial, ENT_QUOTES, 'UTF-8');
        $colors = ['from-blue-500 to-cyan-500', 'from-green-500 to-emerald-500', 'from-purple-500 to-pink-500', 'from-orange-500 to-amber-500'];
        $colorIndex = $user ? ($user->id % count($colors)) : 0;

        return '<span class="w-9 h-9 rounded-full bg-gradient-to-br ' . $colors[$colorIndex] . ' text-white flex items-center justify-center text-sm font-semibold shadow-sm ring-2 ring-white">' . $initial . '</span>';
    }

    protected function assistantAvatarHtml(): string
    {
        $gradient = $this->getAvatarGradient();
        return '<span class="relative"><span class="w-9 h-9 rounded-full bg-gradient-to-br ' . $gradient . ' text-white flex items-center justify-center shadow-sm ring-2 ring-white"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg></span></span>';
    }

    protected function welcomeAvatarHtml(): string
    {
        $gradient = $this->getAvatarGradient();
        return '<div class="relative"><div class="absolute inset-0 rounded-full bg-gradient-to-r ' . $gradient . ' opacity-30 blur-xl animate-pulse"></div><div class="relative w-24 h-24 rounded-full bg-gradient-to-br ' . $gradient . ' flex items-center justify-center shadow-2xl ring-4 ring-white/50"><svg class="w-12 h-12 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg></div></div>';
    }

    protected function getAvatarGradient(): string
    {
        // Check if class has theme() method from HasChatTheme
        if (method_exists($this, 'theme')) {
            return $this->theme()->avatarGradient();
        }
        // Fallback to default
        return 'from-indigo-500 via-purple-500 to-fuchsia-500';
    }
}
