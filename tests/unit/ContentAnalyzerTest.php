<?php

declare(strict_types=1);

namespace Diagnostic\Tests\Unit;

use Diagnostic\Features\Scanner\Core\ContentAnalyzer;
use Diagnostic\Features\Scanner\Core\WPLog;
use PHPUnit\Framework\TestCase;
use WP_Block_Type;
use WP_Block_Type_Registry;

/**
 * @group diagnostic
 * @covers \Diagnostic\Features\Scanner\Core\ContentAnalyzer
 */
final class ContentAnalyzerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WPLog::clear();
        WPLog::set_verbose(false);
        WP_Block_Type_Registry::reset();

        // Reset des caches statiques de ContentAnalyzer
        $this->resetStaticProperty(ContentAnalyzer::class, 'registered_blocks_cache');

        // Reset du cache des règles JSON (variable static locale dans load_analysis_rules)
        // On ne peut pas facilement reset un static local ; on s'en accommode car
        // les tests n'exigent pas de changer de fichier JSON entre eux.

        global $_wp_options, $_diagnostic_filters, $_diagnostic_parse_blocks_return, $_diagnostic_render_block_return;
        $_wp_options = [];
        $_diagnostic_filters = [];
        unset($_diagnostic_parse_blocks_return, $_diagnostic_render_block_return);
    }

    protected function tearDown(): void
    {
        global $_wp_options, $_diagnostic_filters, $_diagnostic_parse_blocks_return, $_diagnostic_render_block_return;
        $_wp_options = [];
        $_diagnostic_filters = [];
        unset($_diagnostic_parse_blocks_return, $_diagnostic_render_block_return);

        $this->resetStaticProperty(ContentAnalyzer::class, 'registered_blocks_cache');
        WP_Block_Type_Registry::reset();
        WPLog::clear();
        parent::tearDown();
    }

    private function resetStaticProperty(string $class, string $property): void
    {
        $ref = new \ReflectionClass($class);
        if ($ref->hasProperty($property)) {
            $prop = $ref->getProperty($property);
            $prop->setValue(null, null);
        }
    }

    private function makePost(int $id, string $content, string $title = 'Test'): \WP_Post
    {
        return new \WP_Post([
            'ID' => $id,
            'post_content' => $content,
            'post_title' => $title,
            'post_status' => 'publish',
        ]);
    }

    // ─── analyze_post_blocks : contenu vide ───

    public function test_empty_content_returns_no_issues(): void
    {
        global $_diagnostic_parse_blocks_return;
        $_diagnostic_parse_blocks_return = [];

        $post = $this->makePost(1, '');
        $issues = ContentAnalyzer::analyze_post_blocks($post);

        self::assertSame([], $issues);
    }

    // ─── validate_single_block : create-block non enregistré ───

    public function test_unregistered_create_block_detected(): void
    {
        global $_diagnostic_parse_blocks_return;

        // Simuler un bloc create-block non enregistré
        $_diagnostic_parse_blocks_return = [
            [
                'blockName' => 'create-block/my-hero',
                'attrs' => [],
                'innerBlocks' => [],
                'innerHTML' => '<div class="hero">Hello</div>',
                'innerContent' => ['<div class="hero">Hello</div>'],
            ],
        ];

        // Pas de bloc enregistré dans le registry
        $post = $this->makePost(1, '<!-- wp:create-block/my-hero --><div class="hero">Hello</div><!-- /wp:create-block/my-hero -->');
        $issues = ContentAnalyzer::analyze_post_blocks($post);

        $types = array_column($issues, 'type');
        self::assertContains('CREATE_BLOCK_UNREGISTERED', $types);
    }

    // ─── validate_single_block : create-block enregistré → pas d'issue ───

    public function test_registered_create_block_no_unregistered_issue(): void
    {
        global $_diagnostic_parse_blocks_return;

        $registry = WP_Block_Type_Registry::get_instance();
        $registry->register('create-block/my-hero', new WP_Block_Type('create-block/my-hero'));

        $_diagnostic_parse_blocks_return = [
            [
                'blockName' => 'create-block/my-hero',
                'attrs' => [],
                'innerBlocks' => [],
                'innerHTML' => '<div>OK</div>',
                'innerContent' => ['<div>OK</div>'],
            ],
        ];

        $post = $this->makePost(2, '<!-- wp:create-block/my-hero --><div>OK</div><!-- /wp:create-block/my-hero -->');
        $issues = ContentAnalyzer::analyze_post_blocks($post);

        $types = array_column($issues, 'type');
        self::assertNotContains('CREATE_BLOCK_UNREGISTERED', $types);
    }

    // ─── validate_single_block : validated block is skipped ───

    public function test_validated_block_is_skipped(): void
    {
        global $_diagnostic_parse_blocks_return, $_wp_options;

        // Marquer le bloc comme validé
        $_wp_options['diagnostic_validated_blocks'] = [
            '1|create-block/hero' => [
                'post_id' => 1,
                'block_name' => 'create-block/hero',
                'validated_at' => '2025-01-01 00:00:00',
            ],
        ];

        $_diagnostic_parse_blocks_return = [
            [
                'blockName' => 'create-block/hero',
                'attrs' => [],
                'innerBlocks' => [],
                'innerHTML' => '<div>X</div>',
                'innerContent' => ['<div>X</div>'],
            ],
        ];

        $post = $this->makePost(1, '<!-- wp:create-block/hero --><div>X</div><!-- /wp:create-block/hero -->');
        $issues = ContentAnalyzer::analyze_post_blocks($post);

        // Aucune issue car le bloc est déjà validé
        $createBlockIssues = array_filter($issues, fn($i) => $i['type'] === 'CREATE_BLOCK_UNREGISTERED');
        self::assertEmpty($createBlockIssues);
    }

    // ─── inner blocks (recursive) ───

    public function test_recursive_inner_blocks_are_checked(): void
    {
        global $_diagnostic_parse_blocks_return;

        $_diagnostic_parse_blocks_return = [
            [
                'blockName' => 'core/group',
                'attrs' => [],
                'innerHTML' => '<div class="group"></div>',
                'innerContent' => ['<div class="group">', null, '</div>'],
                'innerBlocks' => [
                    [
                        'blockName' => 'create-block/nested',
                        'attrs' => [],
                        'innerBlocks' => [],
                        'innerHTML' => '<p>Nested</p>',
                        'innerContent' => ['<p>Nested</p>'],
                    ],
                ],
            ],
        ];

        $post = $this->makePost(3, '');
        $issues = ContentAnalyzer::analyze_post_blocks($post);

        $types = array_column($issues, 'type');
        self::assertContains('CREATE_BLOCK_UNREGISTERED', $types);

        $nested = array_filter($issues, fn($i) => ($i['blockName'] ?? '') === 'create-block/nested');
        self::assertNotEmpty($nested);
    }

    // ─── analyze_raw_content : détection regex dans le contenu brut ───

    public function test_raw_content_detects_create_blocks_via_regex(): void
    {
        global $_diagnostic_parse_blocks_return;

        // Enregistrer le bloc dans le registry (sinon l'atteindre via analyze_block_status)
        // Ne PAS enregistrer → devrait déclencher une issue
        $_diagnostic_parse_blocks_return = [];

        $content = '<!-- wp:create-block/custom-banner {"align":"full"} --><div>Banner</div><!-- /wp:create-block/custom-banner -->';
        $post = $this->makePost(5, $content);

        $issues = ContentAnalyzer::analyze_raw_content($content, $post, null, []);
        // Doit détecter au moins une issue pour le bloc non enregistré
        self::assertNotEmpty($issues);
        self::assertSame('create-block/custom-banner', $issues[0]['blockName']);
    }

    // ─── Filtered issue types ───

    public function test_excluded_issue_types_are_filtered(): void
    {
        global $_diagnostic_parse_blocks_return;

        // Pas de blocs → pas d'issues
        $_diagnostic_parse_blocks_return = [];

        $post = $this->makePost(1, 'plain text');
        $issues = ContentAnalyzer::analyze_post_blocks($post);

        // Vérifier qu'aucun type exclu n'apparaît
        $types = array_column($issues, 'type');
        self::assertNotContains('SERIALIZATION_ERROR', $types);
        self::assertNotContains('INVALID_BLOCK', $types);
        self::assertNotContains('ORPHANED_CONTENT', $types);
    }

    // ─── normalize_html (via reflection car private) ───

    public function test_normalize_html_removes_extra_whitespace(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'normalize_html');

        $result = $method->invoke(null, "  <div>  <p>  Hello  </p>  </div>  ");
        self::assertSame('<div><p> Hello </p></div>', $result);
    }

    public function test_normalize_html_strips_data_block_attributes(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'normalize_html');

        $result = $method->invoke(null, '<div data-block-id="abc123">text</div>');
        self::assertSame('<div>text</div>', $result);
    }

    // ─── check_recovery_markers (via reflection) ───

    public function test_check_recovery_markers_detects_invalid_class(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'check_recovery_markers');

        $result = $method->invoke(null, '<div class="is-invalid">Bad</div>', 'test/block');
        self::assertSame('recovery_mode', $result);
    }

    public function test_check_recovery_markers_detects_convert_to_html(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'check_recovery_markers');

        $result = $method->invoke(null, '<div>Convert to HTML</div>', 'test/block');
        self::assertSame('recovery_mode', $result);
    }

    public function test_check_recovery_markers_returns_valid_for_clean_html(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'check_recovery_markers');

        $result = $method->invoke(null, '<div class="wp-block-cover"><p>Hello</p></div>', 'test/block');
        self::assertSame('valid', $result);
    }

    // ─── is_dynamic_block (via reflection) ───

    public function test_dynamic_block_with_render_callback(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'is_dynamic_block');

        $blockType = new WP_Block_Type('test/dynamic', ['render_callback' => 'my_render_fn']);
        self::assertTrue($method->invoke(null, $blockType));
    }

    public function test_static_block_without_render_callback(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'is_dynamic_block');

        $blockType = new WP_Block_Type('test/static');
        self::assertFalse($method->invoke(null, $blockType));
    }

    // ─── evaluate_json_conditions (via reflection) ───

    public function test_conditions_match_when_api_status_and_registered(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'evaluate_json_conditions');

        $conditions = ['api_status' => ['missing'], 'is_registered' => false];
        self::assertTrue($method->invoke(null, $conditions, 'missing', false));
    }

    public function test_conditions_fail_when_status_mismatch(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'evaluate_json_conditions');

        $conditions = ['api_status' => ['missing'], 'is_registered' => false];
        self::assertFalse($method->invoke(null, $conditions, 'valid', false));
    }

    public function test_conditions_fail_when_registered_mismatch(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'evaluate_json_conditions');

        $conditions = ['api_status' => ['missing'], 'is_registered' => false];
        self::assertFalse($method->invoke(null, $conditions, 'missing', true));
    }

    public function test_unknown_condition_is_ignored(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'evaluate_json_conditions');

        $conditions = ['unknown_key' => 'whatever', 'is_registered' => true];
        self::assertTrue($method->invoke(null, $conditions, 'valid', true));
    }

    // ─── build_issue_from_rule (via reflection) ───

    public function test_build_issue_replaces_block_name_placeholder(): void
    {
        $method = new \ReflectionMethod(ContentAnalyzer::class, 'build_issue_from_rule');

        $rule = [
            'type' => 'TEST_TYPE',
            'severity' => 'high',
            'message' => 'Problem with {block_name}',
            'suggestion' => 'Fix it',
            'action_recommended' => 'remove',
        ];

        $issue = $method->invoke(null, $rule, 'core/test');
        self::assertSame('TEST_TYPE', $issue['type']);
        self::assertSame('Problem with core/test', $issue['message']);
        self::assertSame('remove', $issue['action_recommended']);
    }
}
