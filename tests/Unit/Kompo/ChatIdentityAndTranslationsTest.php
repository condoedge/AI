<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\Support\WelcomeSuggestions;
use Condoedge\Ai\Services\Settings\UserChatSettings;
use Orchestra\Testbench\TestCase;

/**
 * What the chat shows of the assistant (2026-10-21):
 *  - the chat header said "AI Assistant" (hardcoded) and the welcome title defaulted to "AI
 *    Assistant" while the assistant introduces itself by config('ai.identity.name') — one name
 *    everywhere now, the configured one;
 *  - the welcome message and the suggested questions were raw English config strings — they
 *    now go through the translator, so a host language file translates them (a host string
 *    without a translation shows as written).
 */
class ChatIdentityAndTranslationsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.identity.name', 'CondoEdge Assistant');
        $app['config']->set('ai.chat.welcome_title', null);
        $app['config']->set('ai.chat.welcome_message', null);
        $app['config']->set('ai.chat.show_suggestions', true);
        $app['config']->set('ai.chat.max_suggestions', 3);
        $app['config']->set('ai.chat.example_questions', ['Who are the members of my team?', 'Where can I add new members?']);
    }

    /** @test */
    public function the_welcome_title_is_the_assistant_name_unless_a_title_is_configured(): void
    {
        $this->assertSame('CondoEdge Assistant', (new UserChatSettings())->welcomeTitle());

        config(['ai.chat.welcome_title' => 'Building Helper']);
        $this->assertSame('Building Helper', (new UserChatSettings())->welcomeTitle());
    }

    /** @test */
    public function the_default_welcome_message_is_a_translated_line(): void
    {
        // A real JSON language file, as a host ships it (addLines() would split the dotted key).
        $dir = sys_get_temp_dir() . '/ai-lang-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/fr.json', json_encode(['ai.chat.welcome-message' => 'Posez-moi vos questions.']));
        app('translator')->addJsonPath($dir);
        app()->setLocale('fr');

        $this->assertSame('Posez-moi vos questions.', (new UserChatSettings())->welcomeMessage());
    }

    /** @test */
    public function suggested_questions_are_translated_when_the_host_ships_a_translation(): void
    {
        app('translator')->addLines(['*.Who are the members of my team?' => 'Qui sont les membres de mon équipe ?'], 'fr', '*');
        app()->setLocale('fr');

        $this->assertSame(
            ['Qui sont les membres de mon équipe ?', 'Where can I add new members?'],
            WelcomeSuggestions::resolve(null, new UserChatSettings())
        );
    }

    /** @test */
    public function in_the_source_language_the_suggestions_are_unchanged(): void
    {
        app()->setLocale('en');

        $this->assertSame(
            ['Who are the members of my team?', 'Where can I add new members?'],
            WelcomeSuggestions::resolve(null, new UserChatSettings())
        );
    }
}
