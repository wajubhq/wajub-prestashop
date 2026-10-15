<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

/*
 * The module's wordings and their French translation.
 *
 * The wordings are what PrestaShop's own extractor finds: the literal first
 * argument of every `->trans()` whose third argument is a literal
 * `Modules.Wajub.*` domain, in PHP, and of every `{l s='…' d='…'}` in the
 * templates. The French translations are kept in i18n/fr-FR.json (English =>
 * French, by domain), from which the catalogue PrestaShop loads is written:
 * translations/fr-FR/ModulesWajub<Part>.fr-FR.xlf.
 *
 *     php bin/i18n.php list      every wording, by domain, with where it is
 *     php bin/i18n.php missing   the wordings French lacks, as JSON to fill in
 *     php bin/i18n.php build     writes the .xlf catalogues
 *     php bin/i18n.php check     fails when a wording lacks French, a translation
 *                                changes its placeholders or breaks French
 *                                typography, or a catalogue is not up to date
 */

$root = dirname(__DIR__);
$command = $argv[1] ?? 'check';
$frenchFile = $root . '/i18n/fr-FR.json';
$locale = 'fr-FR';

/**
 * @return array<string, array<string, list<string>>> domain => wording => places
 */
function wordings(string $root, array &$problems): array
{
    $found = [];
    $files = [$root . '/wajub.php'];
    foreach (['src', 'controllers', 'views/templates'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), ['php', 'tpl'], true)) {
                $files[] = $file->getPathname();
            }
        }
    }
    sort($files);

    foreach ($files as $path) {
        $relative = substr($path, strlen($root) + 1);
        $code = (string) file_get_contents($path);
        $calls = str_ends_with($path, '.tpl') ? smartyCalls($code) : phpCalls($code, $relative, $problems);
        foreach ($calls as [$wording, $domain, $line]) {
            if (!preg_match('/^Modules\.Wajub\.(Admin|Shop)$/', $domain)) {
                if (!str_starts_with($domain, 'Admin.') && !str_starts_with($domain, 'Shop.')) {
                    $problems[] = "{$relative}:{$line}: domain {$domain} is not one of the module's";
                }

                continue;
            }
            $found[$domain][$wording][] = $relative . ':' . $line;
        }
    }
    ksort($found);

    return $found;
}

/**
 * Every `->trans('literal', …, 'Domain')` of a PHP file, read with PHP's
 * tokenizer: the parameters may hold brackets and calls of their own.
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function phpCalls(string $code, string $relative, array &$problems): array
{
    $tokens = token_get_all($code);
    $calls = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; ++$i) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'trans') {
            continue;
        }
        $before = previous($tokens, $i);
        if ($before === null || !is_array($before) || !in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        $j = next_index($tokens, $i);
        if ($j === null || $tokens[$j] !== '(') {
            continue;
        }
        $line = $tokens[$i][2];
        // The arguments at depth one, each as its tokens.
        $arguments = [[]];
        $depth = 0;
        for ($k = $j; $k < $count; ++$k) {
            $token = $tokens[$k];
            $text = is_array($token) ? $token[1] : $token;
            if (in_array($text, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                ++$depth;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                --$depth;
                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $arguments[] = [];

                continue;
            }
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $arguments[count($arguments) - 1][] = $token;
            }
        }

        $first = $arguments[0];
        $domainTokens = $arguments[2] ?? [];
        $isLiteral = static fn (array $argument): bool => count($argument) === 1 && is_array($argument[0]) && $argument[0][0] === T_CONSTANT_ENCAPSED_STRING;
        if (!$isLiteral($first) || !$isLiteral($domainTokens)) {
            // The translator's own methods pass their arguments on.
            if (!str_starts_with($relative, 'src/Support/')) {
                $problems[] = "{$relative}:{$line}: trans() with a wording or a domain that is not a literal: PrestaShop's extractor would not find it";
            }

            continue;
        }
        $calls[] = [literal($first[0][1]), literal($domainTokens[0][1]), $line];
    }

    return $calls;
}

/**
 * @return list<array{0: string, 1: string, 2: int}>
 */
function smartyCalls(string $code): array
{
    $calls = [];
    preg_match_all("/\\{l\\s+s='((?:[^'\\\\]|\\\\.)*)'(.*?)\\}/s", $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($matches as $match) {
        $domain = preg_match("/\\bd='([^']+)'/", $match[2][0], $d) === 1 ? $d[1] : '';
        $line = substr_count(substr($code, 0, $match[0][1]), "\n") + 1;
        $calls[] = [str_replace("\\'", "'", $match[1][0]), $domain, $line];
    }

    return $calls;
}

function literal(string $token): string
{
    $quote = $token[0];
    $body = substr($token, 1, -1);

    return $quote === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
}

function previous(array $tokens, int $i): mixed
{
    for ($k = $i - 1; $k >= 0; --$k) {
        if (!is_array($tokens[$k]) || $tokens[$k][0] !== T_WHITESPACE) {
            return $tokens[$k];
        }
    }

    return null;
}

function next_index(array $tokens, int $i): ?int
{
    for ($k = $i + 1, $count = count($tokens); $k < $count; ++$k) {
        if (!is_array($tokens[$k]) || $tokens[$k][0] !== T_WHITESPACE) {
            return $k;
        }
    }

    return null;
}

/** @return list<string> the named placeholders of a wording, sorted */
function placeholders(string $text): array
{
    preg_match_all('/%[a-z_]+%/', $text, $matches);
    $found = array_unique($matches[0]);
    sort($found);

    return $found;
}

/**
 * Where a French translation breaks French typography: an ordinary space,
 * or none, before « : ; ! ? », and no non-breaking space inside « ».
 *
 * @return list<string>
 */
function typography(string $french): array
{
    $problems = [];
    // An address (https://), a placeholder or a code is not a sentence.
    $text = (string) preg_replace('#https?://\S+|%[a-z_]+%|\b[a-z_]+\.[a-z_.]+\b|whsec_\S*|sk(_test)?\.\S*#', 'X', $french);
    if (preg_match('/(^|[^\x{00A0}\x{202F}])[:;!?]/u', $text) === 1) {
        $problems[] = 'a « : ; ! ? » without a non-breaking space before it';
    }
    if (preg_match('/«(?!\x{00A0})|(?<!\x{00A0})»/u', $text) === 1) {
        $problems[] = 'a « » without non-breaking spaces inside';
    }
    if (preg_match('/\bSandbox\b|\bLive\b/u', $french) === 1) {
        $problems[] = 'Sandbox or Live left in English: Test, Production';
    }

    return $problems;
}

function xlf(string $domain, array $wordings, array $french, string $locale): string
{
    // By the first file each wording is in, as PrestaShop's own export
    // groups them; without line numbers, so an edit elsewhere in a file
    // leaves the catalogue as it is.
    $byFile = [];
    foreach ($wordings as $wording => $places) {
        $byFile[explode(':', $places[0])[0]][] = $wording;
    }
    ksort($byFile);

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<xliff xmlns=\"urn:oasis:names:tc:xliff:document:1.2\" version=\"1.2\">\n";
    foreach ($byFile as $file => $entries) {
        $xml .= '  <file original="modules/wajub/' . htmlspecialchars($file, ENT_XML1) . '" source-language="en-US" target-language="' . $locale . "\" datatype=\"plaintext\">\n    <body>\n";
        foreach ($entries as $wording) {
            $xml .= '      <trans-unit id="' . md5($wording) . "\">\n"
                . '        <source>' . htmlspecialchars($wording, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</source>\n"
                . '        <target>' . htmlspecialchars((string) ($french[$domain][$wording] ?? $wording), ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</target>\n"
                . "      </trans-unit>\n";
        }
        $xml .= "    </body>\n  </file>\n";
    }

    return $xml . "</xliff>\n";
}

function catalogueFile(string $root, string $domain, string $locale): string
{
    return $root . '/translations/' . $locale . '/' . str_replace('.', '', $domain) . '.' . $locale . '.xlf';
}

$problems = [];
$wordings = wordings($root, $problems);
$french = is_file($frenchFile) ? json_decode((string) file_get_contents($frenchFile), true) : [];
$french = is_array($french) ? $french : [];

switch ($command) {
    case 'list':
        foreach ($wordings as $domain => $entries) {
            echo "== {$domain} (" . count($entries) . ")\n";
            foreach ($entries as $wording => $places) {
                echo '  ' . $wording . '  [' . implode(', ', $places) . "]\n";
            }
        }
        break;

    case 'missing':
        $missing = [];
        foreach ($wordings as $domain => $entries) {
            foreach (array_keys($entries) as $wording) {
                if (($french[$domain][$wording] ?? '') === '') {
                    $missing[$domain][$wording] = '';
                }
            }
        }
        echo json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        break;

    case 'build':
        foreach ($wordings as $domain => $entries) {
            $file = catalogueFile($root, $domain, $locale);
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0755, true);
            }
            file_put_contents($file, xlf($domain, $entries, $french, $locale));
            echo 'Wrote ' . substr($file, strlen($root) + 1) . ' (' . count($entries) . " wordings)\n";
        }
        break;

    case 'check':
    default:
        $total = 0;
        foreach ($wordings as $domain => $entries) {
            foreach ($entries as $wording => $places) {
                ++$total;
                $target = (string) ($french[$domain][$wording] ?? '');
                if ($target === '') {
                    $problems[] = "{$domain}: no French for \"{$wording}\" ({$places[0]})";

                    continue;
                }
                if (placeholders($target) !== placeholders($wording)) {
                    $problems[] = "{$domain}: \"{$wording}\": the French changes the placeholders";
                }
                foreach (typography($target) as $problem) {
                    $problems[] = "{$domain}: \"{$target}\": {$problem}";
                }
            }
            if (!is_file(catalogueFile($root, $domain, $locale)) || file_get_contents(catalogueFile($root, $domain, $locale)) !== xlf($domain, $entries, $french, $locale)) {
                $problems[] = "{$domain}: the catalogue is not up to date: php bin/i18n.php build";
            }
        }
        foreach ($french as $domain => $entries) {
            foreach (array_keys((array) $entries) as $wording) {
                if (!isset($wordings[$domain][$wording])) {
                    $problems[] = "{$domain}: \"{$wording}\" is translated but no longer used";
                }
            }
        }
        foreach ($problems as $problem) {
            echo $problem . "\n";
        }
        echo $problems === [] ? "Translations: {$total} wordings, all in French\n" : count($problems) . " problem(s)\n";
        exit($problems === [] ? 0 : 1);
}

foreach ($problems as $problem) {
    fwrite(STDERR, $problem . "\n");
}
