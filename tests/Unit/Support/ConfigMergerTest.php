<?php
namespace Condoedge\Ai\Tests\Unit\Support;

use Condoedge\Ai\Support\ConfigMerger;
use PHPUnit\Framework\TestCase;

class ConfigMergerTest extends TestCase
{
    public function test_nested_associative_keys_are_merged(): void
    {
        $defaults = ['rag' => ['sample_flag' => false, 'token_budget' => 4000]];
        $host = ['rag' => ['token_budget' => 8000]];
        $this->assertSame(['rag' => ['sample_flag' => false, 'token_budget' => 8000]], ConfigMerger::merge($defaults, $host));
    }

    public function test_lists_are_replaced_whole(): void
    {
        $defaults = ['chat' => ['example_questions' => ['x', 'y', 'z']]];
        $host = ['chat' => ['example_questions' => ['a', 'b']]];
        $this->assertSame(['a', 'b'], ConfigMerger::merge($defaults, $host)['chat']['example_questions']);
    }

    public function test_empty_host_array_replaces(): void
    {
        $this->assertSame([], ConfigMerger::merge(['some_list' => ['p' => 1]], ['some_list' => []])['some_list']);
    }

    public function test_domain_registries_are_replaced_whole(): void
    {
        $defaults = ['entities' => ['App\\Models\\Customer' => ['graph' => []]]];
        $host = ['entities' => ['App\\Models\\Unit' => ['graph' => []]]];
        $this->assertSame(['App\\Models\\Unit'], array_keys(ConfigMerger::merge($defaults, $host)['entities']));
    }

    public function test_replace_whole_applies_only_at_top_level(): void
    {
        $defaults = ['chat' => ['entities' => ['a' => 1, 'b' => 2]]];
        $host = ['chat' => ['entities' => ['b' => 3]]];
        $this->assertSame(['a' => 1, 'b' => 3], ConfigMerger::merge($defaults, $host)['chat']['entities']);
    }

    public function test_host_scalar_and_null_values_win_and_host_only_keys_are_kept(): void
    {
        $defaults = ['log_channel' => 'stack', 'rag' => ['token_budget' => 4000]];
        $host = ['log_channel' => null, 'rag' => 'disabled', 'discovery' => ['x' => 1]];
        $this->assertSame(['log_channel' => null, 'rag' => 'disabled', 'discovery' => ['x' => 1]], ConfigMerger::merge($defaults, $host));
    }

    public function test_host_assoc_replaces_empty_package_default(): void
    {
        $defaults = ['llm_roles' => []];
        $host = ['llm_roles' => ['query_understanding' => ['model' => 'm']]];
        $this->assertSame($host, ConfigMerger::merge($defaults, $host));
    }
}
