<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Model\Cache;

use Hachitechs\HideguardPhp\Rules\PathMatcher;
use Laminas\Uri\Http as Uri;

class ManagedVcl
{
    public function __construct(
        private readonly PolicyFiles $files,
        private readonly SnapshotReader $reader,
        private readonly PathMatcher $paths
    ) {
    }

    public function enabled(): bool
    {
        return $this->files->enabled();
    }

    public function generate(string $template): string
    {
        return $this->files->locked(function () use ($template): string {
            $data = ['generation' => $this->files->generation(), 'stores' => $this->reader->read()];
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            // Use the same canonical JSON representation as descriptor validation.
            $json = json_encode(json_decode($json, true, 32, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
            $id = hash('sha256', $json);
            $result = $this->render($template, $data['stores'], $id, $this->files->marker($id));
            $this->files->write('snapshot-' . $id . '.json', $json);
            return $result;
        });
    }

    /** Pure rendering is also exercised by a real proxy test. */
    public function render(string $template, array $stores, string $id, string $marker): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id) || !str_starts_with($marker, '/')) {
            throw new \InvalidArgumentException('Invalid cache snapshot.');
        }
        if (!preg_match('/\bimport\s+std\s*;/', $template) || !preg_match('/\bacl\s+purge\s*\{/', $template)) {
            throw new \RuntimeException('Managed HideGuard VCL requires std and the native purge ACL.');
        }
        $hosts = [];
        $conditions = [];
        foreach ($stores as $store) {
            foreach ($store['bases'] as $baseUrl) {
                $base = new Uri($baseUrl);
                if (!$base->getHost()) {
                    throw new \RuntimeException('Store base URL requires a host.');
                }
                $host = '(?i)^' . preg_quote($base->getHost(), '~') . '(?::[0-9]+)?$';

                $prefix = trim((string)$base->getPath(), '/');
                $prefix = $prefix === '' ? '' : $prefix . '/';
                $hosts[] = '(req.http.host ~ ' . $this->literal($host) . ' && req.url ~ '
                    . $this->literal('^/' . preg_quote($prefix, '~')) . ')';
                $patterns = [];
                foreach ($store['patterns'] as $pattern) {
                    $patterns[] = preg_quote($prefix, '~')
                        . str_replace('\\*', '[^?]*', preg_quote($this->paths->normalize($pattern), '~'));
                }
                // Chunk alternatives to bound each PCRE and VCL line; overlarge stores use '*' upstream.
                foreach (array_chunk(array_unique($patterns), 50) as $chunk) {
                    $conditions[] = '(req.http.host ~ ' . $this->literal($host) . ' && req.url ~ '
                        . $this->literal('(?i)^/(?:' . implode('|', $chunk) . ')/*(?:[?]|$)') . ')';
                }
                if ($prefix !== '') {
                    $conditions[] = '(req.http.host ~ ' . $this->literal($host) . ' && req.url ~ '
                        . $this->literal('(?i)^/' . preg_quote(rtrim($prefix, '/'), '~') . '(?:[?]|$)') . ')';
                }
            }
        }
        $known = $hosts === [] ? 'false' : '(' . implode(' || ', array_unique($hosts)) . ')';
        $protected = $conditions === [] ? 'false' : '(' . implode(' || ', array_unique($conditions)) . ')';
        $file = $this->literal($marker);
        $recv = "\n    # HideGuard managed snapshot: " . $id . "\n"
            . '    if (req.method == "PURGE" && req.http.X-Hideguard-Snapshot) {' . "\n"
            . '        if (client.ip !~ purge) { return (synth(405)); }' . "\n"
            . '        if (req.http.X-Hideguard-Snapshot != "' . $id . '") { return (synth(409)); }' . "\n"
            . '        if (req.http.X-Hideguard-Stage == "purge") { ban("obj.status != 0"); }' . "\n"
            . '        return (synth(200));' . "\n    }\n"
            . '    if (req.method != "PURGE" && req.url !~ "(?i)^/(static|media)(/|$)") {' . "\n"
            . '        if (!std.file_exists(' . $file . ') || !' . $known . ' || '
            . 'req.url ~ "(?i)(%|\\x5c|//|/[.][.]?(/|[?]|$)|/index[.]php(/|[?]|$)|[?&;]___(from_)?store=)" || '
            . 'req.http.X-Rewrite-URL || req.http.X-Original-URL || req.http.X-Original-URI || '
            . 'req.http.Cookie ~ "(?i)(^|;[ \t]*)store[ \t]*=" || req.http.Store || ' . $protected . ') {' . "\n"
            . "            return (pass);\n        }\n    }\n";
        $template = preg_replace_callback('/(sub\s+vcl_recv\s*\{)/', fn($m) => $m[1] . $recv, $template, 1, $count);
        if ($template === null || $count !== 1) {
            throw new \RuntimeException('Unknown VCL receive template.');
        }
        $hash = "\n    if (req.url !~ \"(?i)^/(static|media)(/|$)\") {\n"
            . '        hash_data("hideguard:' . $id . '");' . "\n    }\n";
        // Partition objects by policy generation, including late fills from an older VCL.
        $template = preg_replace_callback(
            '/(sub\s+vcl_hash\s*\{)/',
            fn($m) => $m[1] . $hash,
            $template,
            1,
            $hashCount
        );
        if ($template === null) {
            throw new \RuntimeException('Unknown VCL hash template.');
        }
        if ($hashCount === 0) {
            $template .= "\nsub vcl_hash {" . $hash . "}\n";
        }
        $synth = "\nsub vcl_synth {\n"
            . '    if (req.method == "PURGE" && req.http.X-Hideguard-Snapshot && client.ip ~ purge) {' . "\n"
            . '        set resp.http.X-Hideguard-Snapshot = "' . $id . '";' . "\n"
            . '        if (std.file_exists(' . $file . ')) { set resp.http.X-Hideguard-Ready = "1"; }' . "\n"
            . '        else { set resp.http.X-Hideguard-Ready = "0"; }' . "\n    }\n}\n";
        return $template . $synth;
    }

    private function literal(string $value): string
    {
        return '"' . preg_replace_callback(
            '/["\x00-\x1f\x7f]/',
            fn($m) => sprintf('\\x%02x', ord($m[0])),
            $value
        ) . '"';
    }
}
