<?php

declare(strict_types=1);

namespace Condoedge\Ai\Tests\Unit\Security;

use Condoedge\Ai\Tests\TestCase;
use Condoedge\Ai\GraphStore\CypherSanitizer;
use Condoedge\Ai\Exceptions\CypherInjectionException;

/**
 * CypherInjectionTest
 *
 * Tests that the CypherSanitizer properly blocks injection attacks
 */
class CypherInjectionTest extends TestCase
{
    /** @test */
    public function it_blocks_malicious_label_with_cypher_commands()
    {
        $this->expectException(CypherInjectionException::class);

        // Attempt to inject DELETE command
        CypherSanitizer::validateLabel("User}) DELETE (n) //");
    }

    /** @test */
    public function it_blocks_label_with_special_characters()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("User; DROP TABLE users;");
    }

    /** @test */
    public function it_blocks_label_with_spaces()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("User Admin");
    }

    /** @test */
    public function it_blocks_label_with_quotes()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("User' OR '1'='1");
    }

    /** @test */
    public function it_blocks_label_with_backticks()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("User` WHERE 1=1 //");
    }

    /** @test */
    public function it_blocks_label_with_cypher_comments()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("User // comment");
    }

    /** @test */
    public function it_blocks_label_starting_with_digit()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("123User");
    }

    /** @test */
    public function it_blocks_empty_label()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("");
    }

    /** @test */
    public function it_blocks_reserved_keyword_as_label()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("MATCH");
    }

    /** @test */
    public function it_blocks_excessively_long_label()
    {
        $this->expectException(CypherInjectionException::class);

        $longLabel = str_repeat('A', 256); // Exceeds 255 char limit
        CypherSanitizer::validateLabel($longLabel);
    }

    /** @test */
    public function it_allows_valid_label_with_letters()
    {
        $result = CypherSanitizer::validateLabel("User");
        $this->assertEquals("User", $result);
    }

    /** @test */
    public function it_allows_valid_label_with_underscores()
    {
        $result = CypherSanitizer::validateLabel("User_Profile");
        $this->assertEquals("User_Profile", $result);
    }

    /** @test */
    public function it_allows_valid_label_with_digits()
    {
        $result = CypherSanitizer::validateLabel("User123");
        $this->assertEquals("User123", $result);
    }

    /** @test */
    public function it_allows_label_starting_with_underscore()
    {
        $result = CypherSanitizer::validateLabel("_InternalUser");
        $this->assertEquals("_InternalUser", $result);
    }

    /** @test */
    public function it_escapes_label_with_backtick_quoting()
    {
        $result = CypherSanitizer::escapeLabel("User");
        $this->assertEquals("`User`", $result);
    }

    /** @test */
    public function it_blocks_malicious_relationship_type()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateRelationshipType("HAS_ORDER}) DELETE (n) //");
    }

    /** @test */
    public function it_allows_valid_relationship_type()
    {
        $result = CypherSanitizer::validateRelationshipType("HAS_ORDER");
        $this->assertEquals("HAS_ORDER", $result);
    }

    /** @test */
    public function it_escapes_relationship_type_with_backtick_quoting()
    {
        $result = CypherSanitizer::escapeRelationshipType("HAS_ORDER");
        $this->assertEquals("`HAS_ORDER`", $result);
    }

    /** @test */
    public function it_blocks_malicious_property_key()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validatePropertyKey("email; DELETE");
    }

    /** @test */
    public function it_allows_valid_property_key()
    {
        $result = CypherSanitizer::validatePropertyKey("email");
        $this->assertEquals("email", $result);
    }

    /** @test */
    public function it_validates_multiple_identifiers()
    {
        $identifiers = ['User', 'Customer_Order', 'Product_123'];
        $result = CypherSanitizer::validateIdentifiers($identifiers, 'label');

        $this->assertEquals($identifiers, $result);
    }

    /** @test */
    public function it_blocks_batch_with_one_invalid_identifier()
    {
        $this->expectException(CypherInjectionException::class);

        $identifiers = ['User', 'Customer; DELETE', 'Product'];
        CypherSanitizer::validateIdentifiers($identifiers, 'label');
    }

    /** @test */
    public function it_blocks_unicode_exploits()
    {
        $this->expectException(CypherInjectionException::class);

        // Zero-width characters and other Unicode tricks
        CypherSanitizer::validateLabel("User\u{200B}");
    }

    /** @test */
    public function it_blocks_null_byte_injection()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("User\0Admin");
    }

    /** @test */
    public function it_blocks_path_traversal_in_label()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("../../../User");
    }

    /** @test */
    public function it_blocks_label_with_parentheses()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("User()");
    }

    /** @test */
    public function it_blocks_label_with_brackets()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("User[0]");
    }

    /** @test */
    public function it_blocks_label_with_curly_braces()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("User{id:1}");
    }

    /** @test */
    public function it_blocks_reserved_keyword_case_insensitive()
    {
        $this->expectException(CypherInjectionException::class);

        // Test that "delete" is blocked even in lowercase
        CypherSanitizer::validateLabel("delete");
    }

    /** @test */
    public function it_blocks_reserved_keyword_mixed_case()
    {
        $this->expectException(CypherInjectionException::class);

        CypherSanitizer::validateLabel("DeLeTe");
    }
}
