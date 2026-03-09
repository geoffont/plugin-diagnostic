<?php

declare(strict_types=1);

namespace Company\Diagnostic\Tests\Unit;

use Company\Diagnostic\Features\BlockRecovery\Core\BlockRecoveryService;
use PHPUnit\Framework\TestCase;

/**
 * @group diagnostic
 * @covers \Company\Diagnostic\Features\BlockRecovery\Core\BlockRecoveryService
 */
final class BlockRecoveryServiceTest extends TestCase
{
    private BlockRecoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        global $_wp_transients, $_diagnostic_posts;
        $_wp_transients = [];
        $_diagnostic_posts = [];
        $this->service = new BlockRecoveryService();
    }

    protected function tearDown(): void
    {
        global $_wp_transients, $_diagnostic_posts;
        $_wp_transients = [];
        $_diagnostic_posts = [];
        parent::tearDown();
    }

    public function test_returns_empty_when_no_transient(): void
    {
        self::assertSame([], $this->service->getPostsToRecover('core/paragraph'));
    }

    public function test_returns_empty_when_transient_has_no_posts(): void
    {
        global $_wp_transients;
        $_wp_transients['diagnostic_scanner_last_results'] = ['posts' => []];

        self::assertSame([], $this->service->getPostsToRecover('core/paragraph'));
    }

    public function test_returns_posts_with_matching_block_recovery_issue(): void
    {
        global $_wp_transients, $_diagnostic_posts;

        $post = new \WP_Post([
            'ID' => 10,
            'post_title' => 'Test Post',
            'post_status' => 'publish',
        ]);
        $_diagnostic_posts[10] = $post;

        $_wp_transients['diagnostic_scanner_last_results'] = [
            'posts' => [
                [
                    'id' => 10,
                    'issues' => [
                        [
                            'type' => 'BLOCK_RECOVERY_MODE',
                            'blockName' => 'create-block/hero',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->getPostsToRecover('create-block/hero');

        self::assertCount(1, $result);
        self::assertSame(10, $result[0]['post_id']);
        self::assertSame('Test Post', $result[0]['post_title']);
        self::assertStringContainsString('post=10', $result[0]['edit_url']);
    }

    public function test_ignores_trashed_posts(): void
    {
        global $_wp_transients, $_diagnostic_posts;

        $post = new \WP_Post([
            'ID' => 20,
            'post_title' => 'Trashed',
            'post_status' => 'trash',
        ]);
        $_diagnostic_posts[20] = $post;

        $_wp_transients['diagnostic_scanner_last_results'] = [
            'posts' => [
                [
                    'id' => 20,
                    'issues' => [
                        [
                            'type' => 'BLOCK_RECOVERY_MODE',
                            'blockName' => 'core/paragraph',
                        ],
                    ],
                ],
            ],
        ];

        self::assertSame([], $this->service->getPostsToRecover('core/paragraph'));
    }

    public function test_ignores_deleted_posts(): void
    {
        global $_wp_transients;

        // Le post n'existe pas dans $_diagnostic_posts
        $_wp_transients['diagnostic_scanner_last_results'] = [
            'posts' => [
                [
                    'id' => 999,
                    'issues' => [
                        [
                            'type' => 'BLOCK_RECOVERY_MODE',
                            'blockName' => 'core/heading',
                        ],
                    ],
                ],
            ],
        ];

        self::assertSame([], $this->service->getPostsToRecover('core/heading'));
    }

    public function test_ignores_non_recovery_issues(): void
    {
        global $_wp_transients, $_diagnostic_posts;

        $_diagnostic_posts[5] = new \WP_Post([
            'ID' => 5,
            'post_title' => 'OK Post',
            'post_status' => 'publish',
        ]);

        $_wp_transients['diagnostic_scanner_last_results'] = [
            'posts' => [
                [
                    'id' => 5,
                    'issues' => [
                        [
                            'type' => 'CREATE_BLOCK_UNREGISTERED',
                            'blockName' => 'create-block/test',
                        ],
                    ],
                ],
            ],
        ];

        self::assertSame([], $this->service->getPostsToRecover('create-block/test'));
    }

    public function test_ignores_different_block_name(): void
    {
        global $_wp_transients, $_diagnostic_posts;

        $_diagnostic_posts[7] = new \WP_Post([
            'ID' => 7,
            'post_title' => 'Post 7',
            'post_status' => 'publish',
        ]);

        $_wp_transients['diagnostic_scanner_last_results'] = [
            'posts' => [
                [
                    'id' => 7,
                    'issues' => [
                        [
                            'type' => 'BLOCK_RECOVERY_MODE',
                            'blockName' => 'core/heading',
                        ],
                    ],
                ],
            ],
        ];

        self::assertSame([], $this->service->getPostsToRecover('core/paragraph'));
    }

    public function test_returns_multiple_posts(): void
    {
        global $_wp_transients, $_diagnostic_posts;

        $_diagnostic_posts[1] = new \WP_Post(['ID' => 1, 'post_title' => 'A', 'post_status' => 'publish']);
        $_diagnostic_posts[2] = new \WP_Post(['ID' => 2, 'post_title' => 'B', 'post_status' => 'publish']);

        $_wp_transients['diagnostic_scanner_last_results'] = [
            'posts' => [
                [
                    'id' => 1,
                    'issues' => [['type' => 'BLOCK_RECOVERY_MODE', 'blockName' => 'core/quote']],
                ],
                [
                    'id' => 2,
                    'issues' => [['type' => 'BLOCK_RECOVERY_MODE', 'blockName' => 'core/quote']],
                ],
            ],
        ];

        $result = $this->service->getPostsToRecover('core/quote');
        self::assertCount(2, $result);
        self::assertSame(1, $result[0]['post_id']);
        self::assertSame(2, $result[1]['post_id']);
    }
}
