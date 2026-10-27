<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Services\Discovery\DomainAliasModels {

    use Illuminate\Database\Eloquent\Model;

    class Campaign extends Model { protected $table = 'campaigns'; }
    class Team extends Model { protected $table = 'teams'; }
}

namespace Condoedge\Ai\Tests\Unit\Services\Discovery {

    use Condoedge\Ai\Services\Discovery\AliasGenerator;
    use Condoedge\Ai\Tests\TestCase;

    /**
     * ai:discover's synonyms: the package's generic business terms plus the host's
     * vocabulary (ai.domain.aliases). A host entry for a word replaces the generic one.
     */
    class AliasGeneratorDomainAliasesTest extends TestCase
    {
        private const MODELS = 'Condoedge\\Ai\\Tests\\Unit\\Services\\Discovery\\DomainAliasModels\\';

        /** @test */
        public function the_host_vocabulary_adds_synonyms(): void
        {
            config(['ai.domain.aliases' => ['campaign' => ['campagne', 'collecte']]]);

            $aliases = (new AliasGenerator())->generate(self::MODELS . 'Campaign');

            $this->assertContains('campagne', $aliases);
            $this->assertContains('collectes', $aliases);
        }

        /** @test */
        public function a_host_entry_replaces_the_generic_terms_of_that_word(): void
        {
            $generic = (new AliasGenerator())->generate(self::MODELS . 'Team');
            $this->assertContains('department', $generic);

            config(['ai.domain.aliases' => ['team' => ['organisme']]]);
            $aliases = (new AliasGenerator())->generate(self::MODELS . 'Team');

            $this->assertContains('organisme', $aliases);
            $this->assertNotContains('department', $aliases);
        }
    }
}
