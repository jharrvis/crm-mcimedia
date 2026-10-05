<?php

namespace App\Domains\Security\Services\WpScan;

use App\Domains\Security\Enums\IncidentSeverity;

/**
 * Mengubah output JSON WPScan CLI menjadi daftar temuan ternormalisasi.
 *
 * Struktur JSON yang diharapkan (WPScan 3.x `--format json`):
 *
 *   {
 *     "version": {"number": "6.4.2", "vulnerabilities": [{"title": ..., "references": {...}}]},
 *     "plugins": {"<slug>": {"version": "...", "vulnerabilities": [...]}},
 *     "themes":  {"<slug>": {"version": "...", "vulnerabilities": [...]}}
 *   }
 *
 * Parser bertahan terhadap field yang hilang (versi WPScan berbeda, scan
 * terpotong): semua akses lewat `?? []` / `?? null` dan entri tanpa judul
 * dilewati — lebih baik kehilangan satu temuan daripada gagal seluruh situs.
 *
 * Pemetaan severity (heuristik terdokumentasi — JSON WPScan tidak membawa
 * skor CVSS):
 *   - pola kritis di judul (SQLi, RCE, upload sembarang, bypass autentikasi,
 *     akses tanpa autentikasi)  -> critical
 *   - kerentanan inti WordPress -> high
 *   - pola ringan di judul (XSS, CSRF, redirect, disclosure) -> medium
 *   - sisanya                   -> high
 */
class WpScanResultParser
{
    private const CRITICAL_PATTERNS = [
        'sql injection',
        'remote code execution',
        'rce',
        'arbitrary file upload',
        'unauthenticated',
        'authentication bypass',
        'privilege escalation',
        'account takeover',
    ];

    private const LOW_PATTERNS = [
        'xss',
        'cross-site scripting',
        'csrf',
        'cross-site request forgery',
        'open redirect',
        'information disclosure',
        'missing authorization',
    ];

    /**
     * @param  array<string, mixed>  $json  hasil json_decode output WPScan
     * @return array{version: ?string, findings: array<int, array{type: string, slug: ?string, component_version: ?string, title: string, severity: IncidentSeverity, references: array<int, string>, fingerprint: string}>}
     */
    public function parse(array $json): array
    {
        $version = $json['version']['number'] ?? null;
        $findings = [];

        foreach ($json['version']['vulnerabilities'] ?? [] as $vuln) {
            $finding = $this->normalize($vuln, 'core', null, $version);

            if ($finding !== null) {
                // Kerentanan inti WordPress selalu minimal high.
                if ($finding['severity']->weight() < IncidentSeverity::High->weight()) {
                    $finding['severity'] = IncidentSeverity::High;
                }

                $findings[] = $finding;
            }
        }

        foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $key => $type) {
            foreach ($json[$key] ?? [] as $slug => $component) {
                if (! is_array($component)) {
                    continue;
                }

                foreach ($component['vulnerabilities'] ?? [] as $vuln) {
                    $finding = $this->normalize($vuln, $type, (string) $slug, $component['version'] ?? null);

                    if ($finding !== null) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        return ['version' => is_string($version) ? $version : null, 'findings' => $findings];
    }

    /**
     * @param  mixed  $vuln
     * @return array{type: string, slug: ?string, component_version: ?string, title: string, severity: IncidentSeverity, references: array<int, string>, fingerprint: string}|null
     */
    private function normalize(mixed $vuln, string $type, ?string $slug, ?string $componentVersion): ?array
    {
        if (! is_array($vuln)) {
            return null;
        }

        $title = trim((string) ($vuln['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        $references = [];

        foreach ($vuln['references'] ?? [] as $urls) {
            foreach ((array) $urls as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $references[] = $url;
                }
            }
        }

        return [
            'type' => $type,
            'slug' => $slug,
            'component_version' => $componentVersion,
            'title' => $title,
            'severity' => $this->mapSeverity($title),
            'references' => array_slice(array_values(array_unique($references)), 0, 5),
            // Sidik jari stabil: komponen + judul. Temuan yang sama pada scan
            // berikutnya menghasilkan external_id identik (idempoten).
            'fingerprint' => substr(sha1($type.'|'.mb_strtolower((string) $slug).'|'.$title), 0, 16),
        ];
    }

    private function mapSeverity(string $title): IncidentSeverity
    {
        $lower = mb_strtolower($title);

        foreach (self::CRITICAL_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return IncidentSeverity::Critical;
            }
        }

        foreach (self::LOW_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return IncidentSeverity::Medium;
            }
        }

        return IncidentSeverity::High;
    }
}