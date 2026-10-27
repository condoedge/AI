<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Snapshots\AliasModels {

    use Illuminate\Database\Eloquent\Model;

    class Union extends Model { protected $table = 'unions'; }
    class Unit extends Model { protected $table = 'units'; }
    class Building extends Model { protected $table = 'buildings'; }
    class Contact extends Model { protected $table = 'contacts'; }
    class Invoice extends Model { protected $table = 'invoices'; }
    class Fund extends Model { protected $table = 'funds'; }
    class Tax extends Model { protected $table = 'taxes'; }
    class Access extends Model { protected $table = 'accesses'; }
    class Customer extends Model { protected $table = 'customers'; }
    class Team extends Model { protected $table = 'teams'; }
    class WorkOrder extends Model { protected $table = 'work_orders'; }
}

namespace Condoedge\Ai\Tests\Unit\Snapshots {

    use Condoedge\Ai\Services\Discovery\AliasGenerator;
    use Condoedge\Ai\Tests\TestCase;

    /**
     * Byte-identity guard for the ai:discover alias vocabulary: the aliases generated
     * for fixed models, under the snapshot host config, must equal the snapshot.
     *
     * Capture or refresh deliberately: UPDATE_PROMPT_SNAPSHOTS=1 vendor/bin/phpunit tests/Unit/Snapshots
     */
    class AliasSnapshotTest extends TestCase
    {
        private const DIR = __DIR__ . '/../../Fixtures/prompt-snapshots';

        /** @test */
        public function generated_aliases(): void
        {
            config(require self::DIR . '/host-config.php');
            $generator = new AliasGenerator();

            $aliases = [];
            foreach (['Union', 'Unit', 'Building', 'Contact', 'Invoice', 'Fund', 'Tax', 'Access', 'Customer', 'Team', 'WorkOrder'] as $model) {
                $aliases[$model] = $generator->generate('Condoedge\\Ai\\Tests\\Unit\\Snapshots\\AliasModels\\' . $model);
            }
            $text = json_encode($aliases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

            $file = self::DIR . '/aliases.json';
            if (getenv('UPDATE_PROMPT_SNAPSHOTS')) {
                file_put_contents($file, $text);
            }
            $this->assertFileExists($file, 'Capture it with UPDATE_PROMPT_SNAPSHOTS=1.');
            $this->assertSame(str_replace("\r\n", "\n", (string) file_get_contents($file)), $text, 'The generated aliases changed.');
        }
    }
}
