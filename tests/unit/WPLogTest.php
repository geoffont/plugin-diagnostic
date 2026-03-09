<?php

declare(strict_types=1);

namespace Company\Diagnostic\Tests\Unit;

use Company\Diagnostic\Features\Scanner\Core\WPLog;
use PHPUnit\Framework\TestCase;

/**
 * @group diagnostic
 * @covers \Company\Diagnostic\Features\Scanner\Core\WPLog
 */
final class WPLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WPLog::clear();
        WPLog::set_verbose(false);
    }

    protected function tearDown(): void
    {
        WPLog::clear();
        WPLog::set_verbose(false);
        parent::tearDown();
    }

    // ─── Verbose mode ───

    public function test_verbose_mode_defaults_to_false(): void
    {
        self::assertFalse(WPLog::is_verbose());
    }

    public function test_set_verbose_enables_verbose(): void
    {
        WPLog::set_verbose(true);
        self::assertTrue(WPLog::is_verbose());
    }

    // ─── Error and Warning (always logged) ───

    public function test_error_is_always_logged(): void
    {
        WPLog::error('Something broke', '[Test]');
        $logs = WPLog::get_logs();

        self::assertCount(1, $logs);
        self::assertSame('ERROR', $logs[0]['level']);
        self::assertSame('Something broke', $logs[0]['message']);
        self::assertSame('[Test]', $logs[0]['context']);
    }

    public function test_warning_is_always_logged(): void
    {
        WPLog::warning('Watch out');
        $logs = WPLog::get_logs();

        self::assertCount(1, $logs);
        self::assertSame('WARNING', $logs[0]['level']);
    }

    // ─── Info and Debug (only in verbose) ───

    public function test_info_is_not_logged_when_not_verbose(): void
    {
        WPLog::info('Some info');
        self::assertCount(0, WPLog::get_logs());
    }

    public function test_info_is_logged_when_verbose(): void
    {
        WPLog::set_verbose(true);
        WPLog::info('Some info');

        $logs = WPLog::get_logs();
        self::assertCount(1, $logs);
        self::assertSame('INFO', $logs[0]['level']);
    }

    public function test_debug_is_not_logged_when_not_verbose(): void
    {
        WPLog::debug('Debug detail');
        self::assertCount(0, WPLog::get_logs());
    }

    public function test_debug_is_logged_when_verbose(): void
    {
        WPLog::set_verbose(true);
        WPLog::debug('Debug detail');

        $logs = WPLog::get_logs();
        self::assertCount(1, $logs);
        self::assertSame('DEBUG', $logs[0]['level']);
    }

    // ─── Legacy add() method ───

    public function test_add_logs_as_info_in_verbose(): void
    {
        WPLog::set_verbose(true);
        WPLog::add('Legacy message', '[Legacy]');

        $logs = WPLog::get_logs();
        self::assertCount(1, $logs);
        self::assertSame('INFO', $logs[0]['level']);
        self::assertSame('[Legacy]', $logs[0]['context']);
    }

    // ─── Filtering ───

    public function test_get_logs_filters_by_level(): void
    {
        WPLog::set_verbose(true);
        WPLog::error('err');
        WPLog::warning('warn');
        WPLog::info('inf');

        $errors = WPLog::get_logs(null, 'ERROR');
        self::assertCount(1, $errors);
        self::assertSame('err', $errors[0]['message']);
    }

    public function test_get_logs_filters_by_context(): void
    {
        WPLog::error('e1', '[Scanner]');
        WPLog::error('e2', '[BlockRegistry]');

        $scanner = WPLog::get_logs('[Scanner]');
        self::assertCount(1, $scanner);
        self::assertSame('e1', $scanner[0]['message']);
    }

    public function test_get_logs_filters_by_level_and_context(): void
    {
        WPLog::set_verbose(true);
        WPLog::error('e', '[A]');
        WPLog::info('i', '[A]');
        WPLog::error('e2', '[B]');

        $result = WPLog::get_logs('[A]', 'ERROR');
        self::assertCount(1, $result);
        self::assertSame('e', $result[0]['message']);
    }

    // ─── Counts ───

    public function test_get_counts_returns_all_levels(): void
    {
        WPLog::set_verbose(true);
        WPLog::error('e');
        WPLog::error('e2');
        WPLog::warning('w');
        WPLog::info('i');
        WPLog::debug('d');

        $counts = WPLog::get_counts();
        self::assertSame(2, $counts['ERROR']);
        self::assertSame(1, $counts['WARNING']);
        self::assertSame(1, $counts['INFO']);
        self::assertSame(1, $counts['DEBUG']);
    }

    // ─── Clear ───

    public function test_clear_removes_all_logs(): void
    {
        WPLog::error('something');
        self::assertCount(1, WPLog::get_logs());

        WPLog::clear();
        self::assertCount(0, WPLog::get_logs());
    }

    // ─── Formatted output ───

    public function test_log_entry_contains_formatted_string(): void
    {
        WPLog::error('Test msg', '[Ctx]');
        $log = WPLog::get_logs()[0];

        self::assertArrayHasKey('formatted', $log);
        self::assertStringContainsString('[Ctx]', $log['formatted']);
        self::assertStringContainsString('[ERROR]', $log['formatted']);
        self::assertStringContainsString('Test msg', $log['formatted']);
    }

    public function test_log_entry_has_timestamp(): void
    {
        WPLog::error('ts test');
        $log = WPLog::get_logs()[0];

        self::assertArrayHasKey('timestamp', $log);
        // Vérifier que c'est un format datetime valide
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $log['timestamp']);
    }
}
