<?php

declare(strict_types=1);

namespace Diagnostic\Tests\Unit;

use Diagnostic\Features\Scanner\Core\BlockRegistry;
use Diagnostic\Features\Scanner\Core\WPLog;
use PHPUnit\Framework\TestCase;
use WP_Block_Type;
use WP_Block_Type_Registry;

/**
 * @group diagnostic
 * @covers \Diagnostic\Features\Scanner\Core\BlockRegistry
 */
final class BlockRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WPLog::clear();
        WPLog::set_verbose(false);
        WP_Block_Type_Registry::reset();
    }

    protected function tearDown(): void
    {
        WP_Block_Type_Registry::reset();
        WPLog::clear();
        parent::tearDown();
    }

    // ─── is_block_registered ───

    public function test_registered_block_returns_true(): void
    {
        $registry = WP_Block_Type_Registry::get_instance();
        $registry->register('core/paragraph', new WP_Block_Type('core/paragraph'));

        self::assertTrue(BlockRegistry::is_block_registered('core/paragraph'));
    }

    public function test_unregistered_block_returns_false(): void
    {
        self::assertFalse(BlockRegistry::is_block_registered('core/nonexistent'));
    }

    public function test_empty_block_name_returns_false(): void
    {
        self::assertFalse(BlockRegistry::is_block_registered(''));
    }

    // ─── is_create_block ───

    public function test_create_block_prefix_detected(): void
    {
        self::assertTrue(BlockRegistry::is_create_block('create-block/my-custom'));
    }

    public function test_core_block_is_not_create_block(): void
    {
        self::assertFalse(BlockRegistry::is_create_block('core/paragraph'));
    }

    public function test_empty_string_is_not_create_block(): void
    {
        self::assertFalse(BlockRegistry::is_create_block(''));
    }

    public function test_partial_prefix_is_not_create_block(): void
    {
        self::assertFalse(BlockRegistry::is_create_block('create-bloc/test'));
    }

    // ─── get_valid_post_types ───

    public function test_get_valid_post_types_excludes_attachment(): void
    {
        global $_diagnostic_post_types;
        $_diagnostic_post_types = [
            'post' => 'post',
            'page' => 'page',
            'attachment' => 'attachment',
            'custom' => 'custom',
        ];

        $result = BlockRegistry::get_valid_post_types();

        self::assertContains('post', $result);
        self::assertContains('page', $result);
        self::assertContains('custom', $result);
        self::assertNotContains('attachment', $result);

        unset($_diagnostic_post_types);
    }

    public function test_get_valid_post_types_excludes_all_special_types(): void
    {
        global $_diagnostic_post_types;
        $_diagnostic_post_types = [
            'post' => 'post',
            'attachment' => 'attachment',
            'revision' => 'revision',
            'nav_menu_item' => 'nav_menu_item',
            'customize_changeset' => 'customize_changeset',
            'oembed_cache' => 'oembed_cache',
        ];

        $result = BlockRegistry::get_valid_post_types();

        self::assertSame(['post'], $result);

        unset($_diagnostic_post_types);
    }
}
