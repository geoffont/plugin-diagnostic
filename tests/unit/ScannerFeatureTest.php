<?php

declare(strict_types=1);

namespace Diagnostic\Tests\Unit;

use Diagnostic\Features\Scanner\Feature;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * @group diagnostic
 * @covers \Diagnostic\Features\Scanner\Feature
 */
final class ScannerFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $_diagnostic_rest_routes, $_diagnostic_posts, $_diagnostic_user_caps;
        $_diagnostic_rest_routes = [];
        $_diagnostic_posts = [];
        $_diagnostic_user_caps = [];
    }

    protected function tearDown(): void
    {
        global $_diagnostic_rest_routes, $_diagnostic_posts, $_diagnostic_user_caps;
        $_diagnostic_rest_routes = [];
        $_diagnostic_posts = [];
        $_diagnostic_user_caps = [];
        parent::tearDown();
    }

    // ─── register_rest_endpoints ───

    public function testRegisterRestEndpointsRegistersValidatePost(): void
    {
        Feature::register_rest_endpoints();

        global $_diagnostic_rest_routes;
        $this->assertArrayHasKey('diagnostic/v1/validate-post', $_diagnostic_rest_routes);
    }

    public function testRegisterRestEndpointsRegistersPostsContent(): void
    {
        Feature::register_rest_endpoints();

        global $_diagnostic_rest_routes;
        $this->assertArrayHasKey('diagnostic/v1/posts-content', $_diagnostic_rest_routes);
    }

    public function testPostsContentEndpointUsesPostMethod(): void
    {
        Feature::register_rest_endpoints();

        global $_diagnostic_rest_routes;
        $route = $_diagnostic_rest_routes['diagnostic/v1/posts-content'];
        $this->assertSame('POST', $route['methods']);
    }

    public function testPostsContentEndpointRequiresIds(): void
    {
        Feature::register_rest_endpoints();

        global $_diagnostic_rest_routes;
        $route = $_diagnostic_rest_routes['diagnostic/v1/posts-content'];
        $this->assertTrue($route['args']['ids']['required']);
    }

    // ─── rest_batch_posts_content ───

    public function testBatchPostsContentReturnsPostsContent(): void
    {
        global $_diagnostic_posts;
        $_diagnostic_posts[1] = new \WP_Post([
            'ID' => 1,
            'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
            'post_status' => 'publish',
        ]);
        $_diagnostic_posts[2] = new \WP_Post([
            'ID' => 2,
            'post_content' => '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading -->',
            'post_status' => 'publish',
        ]);

        $request = new WP_REST_Request();
        $request->set_param('ids', [1, 2]);

        $result = Feature::rest_batch_posts_content($request);

        $this->assertArrayHasKey('posts', $result);
        $this->assertCount(2, $result['posts']);
        $this->assertSame(1, $result['posts'][0]['id']);
        $this->assertStringContainsString('Hello', $result['posts'][0]['content']);
        $this->assertSame(2, $result['posts'][1]['id']);
        $this->assertStringContainsString('Title', $result['posts'][1]['content']);
    }

    public function testBatchPostsContentSkipsTrashed(): void
    {
        global $_diagnostic_posts;
        $_diagnostic_posts[1] = new \WP_Post([
            'ID' => 1,
            'post_content' => 'content',
            'post_status' => 'trash',
        ]);

        $request = new WP_REST_Request();
        $request->set_param('ids', [1]);

        $result = Feature::rest_batch_posts_content($request);

        $this->assertCount(0, $result['posts']);
    }

    public function testBatchPostsContentSkipsNonExistent(): void
    {
        $request = new WP_REST_Request();
        $request->set_param('ids', [999]);

        $result = Feature::rest_batch_posts_content($request);

        $this->assertCount(0, $result['posts']);
    }

    public function testBatchPostsContentReturnsEmptyForEmptyIds(): void
    {
        $request = new WP_REST_Request();
        $request->set_param('ids', []);

        $result = Feature::rest_batch_posts_content($request);

        $this->assertArrayHasKey('posts', $result);
        $this->assertCount(0, $result['posts']);
    }

    public function testBatchPostsContentHandlesMixedExistAndNonExist(): void
    {
        global $_diagnostic_posts;
        $_diagnostic_posts[5] = new \WP_Post([
            'ID' => 5,
            'post_content' => 'exists',
            'post_status' => 'publish',
        ]);

        $request = new WP_REST_Request();
        $request->set_param('ids', [5, 999]);

        $result = Feature::rest_batch_posts_content($request);

        $this->assertCount(1, $result['posts']);
        $this->assertSame(5, $result['posts'][0]['id']);
    }

    // ─── Validation callback for ids ───

    public function testIdsValidationRejectsEmptyArray(): void
    {
        Feature::register_rest_endpoints();

        global $_diagnostic_rest_routes;
        $validate = $_diagnostic_rest_routes['diagnostic/v1/posts-content']['args']['ids']['validate_callback'];

        $this->assertFalse($validate([]));
    }

    public function testIdsValidationRejectsMoreThan50(): void
    {
        Feature::register_rest_endpoints();

        global $_diagnostic_rest_routes;
        $validate = $_diagnostic_rest_routes['diagnostic/v1/posts-content']['args']['ids']['validate_callback'];

        $this->assertFalse($validate(range(1, 51)));
    }

    public function testIdsValidationRejectsNegativeIds(): void
    {
        Feature::register_rest_endpoints();

        global $_diagnostic_rest_routes;
        $validate = $_diagnostic_rest_routes['diagnostic/v1/posts-content']['args']['ids']['validate_callback'];

        $this->assertFalse($validate([-1]));
    }

    public function testIdsValidationAcceptsValidArray(): void
    {
        Feature::register_rest_endpoints();

        global $_diagnostic_rest_routes;
        $validate = $_diagnostic_rest_routes['diagnostic/v1/posts-content']['args']['ids']['validate_callback'];

        $this->assertTrue($validate([1, 2, 3]));
    }

    public function testIdsValidationRejectsNonArray(): void
    {
        Feature::register_rest_endpoints();

        global $_diagnostic_rest_routes;
        $validate = $_diagnostic_rest_routes['diagnostic/v1/posts-content']['args']['ids']['validate_callback'];

        $this->assertFalse($validate('not-array'));
    }
}
