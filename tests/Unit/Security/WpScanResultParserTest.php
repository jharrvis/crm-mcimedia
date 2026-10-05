<?php

namespace Tests\Unit\Security;

use App\Domains\Security\Enums\IncidentSeverity;
use App\Domains\Security\Services\WpScan\WpScanResultParser;
use Tests\TestCase;

/**
 * Unit test parser output JSON WPScan (t_2e555b0b).
 */
class WpScanResultParserTest extends TestCase
{
    private WpScanResultParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new WpScanResultParser();
    }

    public function test_parses_core_plugin_and_theme_vulnerabilities(): void
    {
        $result = $this->parser->parse([
            'version' => [
                'number' => '6.3.1',
                'vulnerabilities' => [
                    ['title' => 'WordPress 6.3.1 - Unauthenticated RCE'],
                ],
            ],
            'plugins' => [
                'woocommerce' => [
                    'version' => '8.0.0',
                    'vulnerabilities' => [
                        ['title' => 'WooCommerce < 8.0.1 - Reflected XSS'],
                    ],
                ],
            ],
            'themes' => [
                'astra' => [
                    'version' => '4.1.0',
                    'vulnerabilities' => [
                        ['title' => 'Astra < 4.1.2 - CSRF to Settings Update'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('6.3.1', $result['version']);
        $this->assertCount(3, $result['findings']);

        $byType = [];

        foreach ($result['findings'] as $finding) {
            $byType[$finding['type']] = $finding;
        }

        $this->assertArrayHasKey('core', $byType);
        $this->assertArrayHasKey('plugin', $byType);
        $this->assertArrayHasKey('theme', $byType);

        $this->assertSame('woocommerce', $byType['plugin']['slug']);
        $this->assertSame('8.0.0', $byType['plugin']['component_version']);
        $this->assertSame('astra', $byType['theme']['slug']);
    }

    public function test_severity_mapping(): void
    {
        // Pola kritis diuji di inti; pola ringan (XSS/CSRF/redirect) diuji di
        // plugin karena temuan inti sengaja diberi lantai minimal High
        // (kerentanan core WordPress berdampak pada semua situs klien).
        $result = $this->parser->parse([
            'version' => [
                'number' => '6.0',
                'vulnerabilities' => [
                    ['title' => 'SQL Injection in core'],
                    ['title' => 'Remote Code Execution flaw'],
                    ['title' => 'Unauthenticated file upload'],
                    ['title' => 'Some unknown vulnerability'],
                    ['title' => 'Reflected XSS in core'], // lantai core -> High
                ],
            ],
            'plugins' => [
                'akismet' => [
                    'version' => '5.0',
                    'vulnerabilities' => [
                        ['title' => 'Reflected XSS'],
                        ['title' => 'CSRF in admin action'],
                        ['title' => 'Open redirect on login'],
                    ],
                ],
            ],
        ]);

        $core = array_map(fn ($f) => $f['severity'], array_slice($result['findings'], 0, 5));
        $plugins = array_map(fn ($f) => $f['severity'], array_slice($result['findings'], 5));

        $this->assertSame(IncidentSeverity::Critical, $core[0]); // SQL injection
        $this->assertSame(IncidentSeverity::Critical, $core[1]); // RCE
        $this->assertSame(IncidentSeverity::Critical, $core[2]); // unauthenticated
        $this->assertSame(IncidentSeverity::High, $core[3]); // fallback
        $this->assertSame(IncidentSeverity::High, $core[4]); // XSS di core dinaikkan ke High

        $this->assertSame(IncidentSeverity::Medium, $plugins[0]); // XSS
        $this->assertSame(IncidentSeverity::Medium, $plugins[1]); // CSRF
        $this->assertSame(IncidentSeverity::Medium, $plugins[2]); // redirect
    }

    public function test_core_vulnerability_is_at_least_high(): void
    {
        $result = $this->parser->parse([
            'version' => [
                'number' => '6.0',
                'vulnerabilities' => [
                    // XSS di inti WordPress tetap minimum high (bukan medium).
                    ['title' => 'WordPress core XSS'],
                ],
            ],
        ]);

        $this->assertSame(IncidentSeverity::High, $result['findings'][0]['severity']);
    }

    public function test_fingerprint_is_stable_across_calls(): void
    {
        $json = [
            'plugins' => [
                'akismet' => [
                    'version' => '5.0',
                    'vulnerabilities' => [['title' => 'Akismet XSS']],
                ],
            ],
        ];

        $first = $this->parser->parse($json);
        $second = $this->parser->parse($json);

        $this->assertSame($first['findings'][0]['fingerprint'], $second['findings'][0]['fingerprint']);
        $this->assertSame(16, strlen($first['findings'][0]['fingerprint']));
    }

    public function test_different_titles_produce_different_fingerprints(): void
    {
        $result = $this->parser->parse([
            'plugins' => [
                'p' => [
                    'vulnerabilities' => [
                        ['title' => 'Bug A'],
                        ['title' => 'Bug B'],
                    ],
                ],
            ],
        ]);

        $this->assertNotSame(
            $result['findings'][0]['fingerprint'],
            $result['findings'][1]['fingerprint'],
        );
    }

    public function test_empty_or_missing_structure_is_safe(): void
    {
        $this->assertSame(['version' => null, 'findings' => []], $this->parser->parse([]));
        $this->assertSame(['version' => null, 'findings' => []], $this->parser->parse(['version' => []]));
    }

    public function test_entries_without_title_are_skipped(): void
    {
        $result = $this->parser->parse([
            'plugins' => [
                'x' => [
                    'vulnerabilities' => [
                        ['references' => []], // tanpa title
                        ['title' => 'Valid finding'],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $result['findings']);
        $this->assertSame('Valid finding', $result['findings'][0]['title']);
    }

    public function test_references_are_extracted_and_capped(): void
    {
        $urls = [];

        for ($i = 1; $i <= 8; $i++) {
            $urls[] = "https://wpscan.com/vulnerability/{$i}";
        }

        $result = $this->parser->parse([
            'plugins' => [
                'x' => [
                    'vulnerabilities' => [
                        ['title' => 'Many refs', 'references' => ['url' => $urls]],
                    ],
                ],
            ],
        ]);

        $this->assertCount(5, $result['findings'][0]['references']);
    }

    public function test_non_array_entries_are_ignored(): void
    {
        $result = $this->parser->parse([
            'plugins' => [
                'x' => [
                    'vulnerabilities' => ['bukan-array', ['title' => 'Valid']],
                ],
            ],
        ]);

        $this->assertCount(1, $result['findings']);
    }
}