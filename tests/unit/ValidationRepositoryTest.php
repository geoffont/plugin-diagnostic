<?php

declare(strict_types=1);

namespace Diagnostic\Tests\Unit;

use Diagnostic\Features\BlockRecovery\Core\ValidationRepository;
use PHPUnit\Framework\TestCase;

/**
 * @group diagnostic
 * @covers \Diagnostic\Features\BlockRecovery\Core\ValidationRepository
 */
final class ValidationRepositoryTest extends TestCase
{
    private ValidationRepository $repo;

    private static function resetPostIdsCache(): void
    {
        $prop = (new \ReflectionClass(ValidationRepository::class))
            ->getProperty('existing_post_ids_cache');
        $prop->setValue(null, null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $_wp_options;
        $_wp_options = [];
        self::resetPostIdsCache();
        $this->repo = new ValidationRepository();
    }

    protected function tearDown(): void
    {
        global $_wp_options;
        $_wp_options = [];
        parent::tearDown();
    }

    // ─── getAll ───

    public function test_getAll_returns_empty_array_by_default(): void
    {
        self::assertSame([], $this->repo->getAll());
    }

    // ─── markAsValidated / isValidated ───

    public function test_markAsValidated_stores_entry(): void
    {
        $this->repo->markAsValidated(42, 'core/paragraph');

        self::assertTrue($this->repo->isValidated(42, 'core/paragraph'));
    }

    public function test_isValidated_returns_false_for_unknown(): void
    {
        self::assertFalse($this->repo->isValidated(99, 'core/heading'));
    }

    public function test_markAsValidated_stores_correct_data(): void
    {
        $this->repo->markAsValidated(10, 'core/image');
        $all = $this->repo->getAll();

        self::assertArrayHasKey('10|core/image', $all);
        $entry = $all['10|core/image'];
        self::assertSame(10, $entry['post_id']);
        self::assertSame('core/image', $entry['block_name']);
        self::assertArrayHasKey('validated_at', $entry);
    }

    public function test_multiple_validations_for_same_block(): void
    {
        $this->repo->markAsValidated(1, 'core/paragraph');
        $this->repo->markAsValidated(2, 'core/paragraph');
        $this->repo->markAsValidated(3, 'core/heading');

        $all = $this->repo->getAll();
        self::assertCount(3, $all);
    }

    // ─── resetAll ───

    public function test_resetAll_clears_all_validations(): void
    {
        $this->repo->markAsValidated(1, 'core/paragraph');
        $this->repo->markAsValidated(2, 'core/heading');

        $this->repo->resetAll();
        self::assertSame([], $this->repo->getAll());
    }

    // ─── countValidatedForBlock ───

    public function test_countValidatedForBlock_with_existing_posts(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->set_col_results(['1', '2']);

        $this->repo->markAsValidated(1, 'core/paragraph');
        $this->repo->markAsValidated(2, 'core/paragraph');
        $this->repo->markAsValidated(3, 'core/heading');

        self::resetPostIdsCache();

        $count = $this->repo->countValidatedForBlock('core/paragraph');
        self::assertSame(2, $count);
    }

    public function test_countValidatedForBlock_excludes_deleted_posts(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->set_col_results(['1']);

        $this->repo->markAsValidated(1, 'core/paragraph');
        $this->repo->markAsValidated(2, 'core/paragraph');

        self::resetPostIdsCache();

        $count = $this->repo->countValidatedForBlock('core/paragraph');
        self::assertSame(1, $count);
    }

    // ─── canAutoRecover ───

    public function test_canAutoRecover_requires_at_least_two_validations(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->set_col_results(['1']);

        $this->repo->markAsValidated(1, 'core/paragraph');

        self::resetPostIdsCache();

        self::assertFalse($this->repo->canAutoRecover('core/paragraph'));
    }

    public function test_canAutoRecover_true_with_two_validations(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->set_col_results(['1', '2']);

        $this->repo->markAsValidated(1, 'core/paragraph');
        $this->repo->markAsValidated(2, 'core/paragraph');

        self::resetPostIdsCache();

        self::assertTrue($this->repo->canAutoRecover('core/paragraph'));
    }

    // ─── cleanupDeletedPosts ───

    public function test_cleanupDeletedPosts_removes_orphans(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->set_col_results(['1']);

        $this->repo->markAsValidated(1, 'core/paragraph');
        $this->repo->markAsValidated(2, 'core/heading');

        self::resetPostIdsCache();

        $cleaned = $this->repo->cleanupDeletedPosts();

        self::assertSame(1, $cleaned);
        $all = $this->repo->getAll();
        self::assertCount(1, $all);
        self::assertArrayHasKey('1|core/paragraph', $all);
    }

    public function test_cleanupDeletedPosts_returns_zero_when_all_exist(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->set_col_results(['1', '2']);

        $this->repo->markAsValidated(1, 'core/paragraph');
        $this->repo->markAsValidated(2, 'core/heading');

        self::resetPostIdsCache();

        $cleaned = $this->repo->cleanupDeletedPosts();
        self::assertSame(0, $cleaned);
    }

    // ─── countAllValidatedPosts (static) ───

    public function test_countAllValidatedPosts_counts_unique_existing(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->set_col_results(['1', '2']);

        $this->repo->markAsValidated(1, 'core/paragraph');
        $this->repo->markAsValidated(2, 'core/heading');
        $this->repo->markAsValidated(99, 'core/image');

        self::resetPostIdsCache();

        $count = ValidationRepository::countAllValidatedPosts();
        self::assertSame(2, $count);
    }

    // ─── getValidatedBlockNames (static) ───

    public function test_getValidatedBlockNames_returns_blocks_with_2_plus(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->set_col_results(['1', '2', '3']);

        $this->repo->markAsValidated(1, 'core/paragraph');
        $this->repo->markAsValidated(2, 'core/paragraph');
        $this->repo->markAsValidated(3, 'core/heading');

        self::resetPostIdsCache();

        $names = ValidationRepository::getValidatedBlockNames();
        self::assertSame(['core/paragraph'], $names);
    }
}
