<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Kompo;

use Condoedge\Ai\Kompo\Support\RichDataRenderer;
use Condoedge\Ai\Services\Settings\ChatSettingsInterface;
use Condoedge\Ai\Services\UI\ChatThemeInterface;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * The "Referenced Files" cards under an assistant message.
 *
 * Live 2026-10-08 (Error 500 in the chat): a message that cited an UPLOADED file carries an
 * INTEGER file id (14655); the card tested `str_starts_with($file['id'], 'physical:')` under
 * strict types and the whole conversation stopped rendering. A project file has a string id
 * ("physical:…"); an uploaded file an integer one. And a cited file may be gone by the time
 * the conversation is opened again: its card is then its name, never a crash.
 */
class FileReferenceCardTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/KompoHelpers.php';
        require_once __DIR__ . '/../../../vendor/kompo/kompo/src/Core/HelperUtils.php';
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** The renderer with the file lookup replaced: $found[id] is the model, a missing id is a deleted file. */
    private function renderer(array $found = []): RichDataRenderer
    {
        return new class(Mockery::mock(ChatThemeInterface::class), Mockery::mock(ChatSettingsInterface::class), $found) extends RichDataRenderer {
            public array $looked = [];

            public function __construct($theme, $settings, private array $found)
            {
                parent::__construct($theme, $settings);
            }

            protected function findFile(int|string $id): mixed
            {
                $this->looked[] = $id;

                return $this->found[$id] ?? null;
            }
        };
    }

    private function html(mixed $element): string
    {
        return json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** @test */
    public function an_uploaded_file_with_an_integer_id_renders_instead_of_throwing(): void
    {
        $renderer = $this->renderer();

        $rendered = $renderer->renderFileReferences([['id' => 14655, 'name' => 'Déclaration de copropriété.pdf']]);

        $this->assertNotNull($rendered);
        $this->assertSame([14655], $renderer->looked, 'the uploaded file is looked up by its id');
        $this->assertStringContainsString('Déclaration de copropriété.pdf', $this->html($rendered), 'a file that is gone still shows its name');
    }

    /** @test */
    public function a_project_file_is_shown_by_name_and_never_looked_up(): void
    {
        $renderer = $this->renderer();

        $rendered = $renderer->renderFileReferences([['id' => 'physical:public/laws/code.md', 'name' => 'code.md']]);

        $this->assertSame([], $renderer->looked);
        $this->assertNotNull($rendered);
    }

    /** @test */
    public function internal_files_can_be_left_out_of_the_sources(): void
    {
        config(['ai.chat.show_internal_file_references' => false]);
        $internal = ['id' => 'physical:help/article.md', 'name' => 'article.md'];

        $this->assertNull($this->renderer()->renderFileReferences([$internal]), 'Nothing the reader can open: no Sources block.');

        $listed = $this->html($this->renderer()->renderFileReferences([$internal, ['id' => 7, 'name' => 'minutes.pdf']]));
        $this->assertStringNotContainsString('article.md', $listed);
        $this->assertStringContainsString('minutes.pdf', $listed);
    }

    /** @test */
    public function a_reference_without_an_id_or_a_name_does_not_break_the_message(): void
    {
        $renderer = $this->renderer();

        $this->assertNotNull($renderer->renderFileReferences([['name' => 'orphan.pdf'], ['id' => 7]]));
        $this->assertSame([7], $renderer->looked);
    }

    /** @test */
    public function a_file_name_is_escaped_in_the_card(): void
    {
        $html = $this->html($this->renderer()->renderFileReferences([['id' => 9, 'name' => '<img src=x onerror=alert(1)>.pdf']]));

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }
}
