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
 * The PrestaShop Addons rules a module's files are checked against, on the
 * files the zip ships (or on an unpacked zip: `php bin/lint-module.php <dir>`):
 *
 * - an index.php in every directory, so none can be listed;
 * - the license header (@author, @copyright, @license) in every PHP, JS,
 *   CSS and template file;
 * - every PHP file but index.php stops when PrestaShop did not load it;
 * - no function the Addons validator refuses, nor debugging left behind;
 * - no request value read but through Tools (getValue(), getAllValues()),
 *   nor the server's globals ($_SERVER: Symfony's Request reads them), no
 *   file read or copied but through Tools, as the validator asks;
 * - no HTML printed from PHP: templates draw the pages;
 * - every variable a template prints is escaped (`|escape:'htmlall':'UTF-8'`,
 *   or a number: `|intval`, `|floatval`), and every wording of a template
 *   is in one of the module's domains;
 * - config.xml says what the module class says: its version
 *   (Wajub::VERSION), name, tab, author, display name, description and
 *   uninstall confirmation; and each e-mail template exists in English and
 *   French, as HTML and as text.
 *
 * Prints each problem, and exits 1 when there is one.
 */

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');

/** What the zip leaves out (bin/build-zip.sh): not checked here either. */
$skipped = ['.git', 'vendor', 'tests', 'bin', 'build', '.phpstan', 'node_modules', 'i18n'];

$problems = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($root, $skipped): bool {
            $relative = substr($file->getPathname(), strlen($root) + 1);

            $first = explode('/', $relative)[0];

            // Dot files (.php-cs-fixer.dist.php, .gitignore) are the
            // developers' own, and never ship either.
            return !in_array($first, $skipped, true) && $first[0] !== '.';
        }
    ),
    RecursiveIteratorIterator::SELF_FIRST
);

$forbiddenPhp = [
    // PHP's own functions, not a method of the same name (Hook::exec()).
    '/(?<!::|->)\b(eval|exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(/' => 'a function that runs code or commands',
    '/\b(serialize|unserialize)\s*\(/' => 'serialize()/unserialize(): use JSON',
    '/\b(var_dump|print_r|var_export|debug_zval_dump|dump)\s*\(/' => 'debugging output',
    '/\bdie\s*\(/' => 'die(): answer, then exit',
    '/\bdirname\s*\(\s*__FILE__\s*\)/' => 'dirname(__FILE__)',
    '/\$_(GET|POST|REQUEST|COOKIE|FILES)\b/' => 'a superglobal read directly: use Tools::getValue() or Tools::getAllValues()',
    '/\$_(SERVER|ENV)\b/' => 'a server global read directly: use Symfony\'s Request (Controller\\FrontAnswers)',
    '/(?<!::)\bfile_get_contents\s*\(/' => 'file_get_contents(): use Tools::file_get_contents()',
    '/(?<!::|->)\bcopy\s*\(/' => 'copy(): use Tools::copy()',
    '/(?<!\w)echo\s+[\'"]\s*</' => 'HTML printed from PHP: use a template',
];
$forbiddenJs = [
    '/\bconsole\.(log|debug|info|dir|table)\s*\(/' => 'console output',
    '/\bdebugger\b/' => 'debugger statement',
    '/\beval\s*\(/' => 'eval()',
];

foreach ($iterator as $file) {
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if ($file->isDir()) {
        if (!is_file($file->getPathname() . '/index.php')) {
            $problems[] = $relative . '/: no index.php';
        }

        continue;
    }

    $extension = strtolower($file->getExtension());
    if (!in_array($extension, ['php', 'js', 'css', 'tpl', 'twig'], true)) {
        continue;
    }
    $content = (string) file_get_contents($file->getPathname());
    $head = substr($content, 0, 600);
    foreach (['@author', '@copyright', '@license'] as $tag) {
        if (!str_contains($head, $tag)) {
            $problems[] = $relative . ': no ' . $tag . ' in its header';
        }
    }

    if ($extension === 'php') {
        if (basename($relative) !== 'index.php' && !preg_match("/if \(!defined\('_PS_VERSION_'\)\) \{\s*exit;\s*\}/", $content)) {
            $problems[] = $relative . ': does not stop when PrestaShop did not load it (_PS_VERSION_)';
        }
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $content) ?? $content;
        foreach ($forbiddenPhp as $pattern => $why) {
            if (preg_match($pattern, $code) === 1) {
                $problems[] = $relative . ': ' . $why;
            }
        }
    } elseif ($extension === 'tpl') {
        // Smarty comments say nothing to anyone.
        $code = preg_replace('/\{\*.*?\*\}/s', '', $content) ?? $content;
        if (preg_match_all('/\{\$[^}]*\}/', $code, $outputs) > 0) {
            foreach ($outputs[0] as $output) {
                if (preg_match("/\\|(escape:'htmlall':'UTF-8'|intval|floatval)\\s*\\}$/", $output) !== 1) {
                    $problems[] = $relative . ': ' . $output . ' is printed unescaped: add |escape:\'htmlall\':\'UTF-8\'';
                }
            }
        }
        if (preg_match_all('/\{l\s[^}]*\}/', $code, $wordings) > 0) {
            foreach ($wordings[0] as $wording) {
                if (preg_match("/\\sd='Modules\\.Wajub\\.(Admin|Shop)'/", $wording) !== 1) {
                    $problems[] = $relative . ': ' . $wording . ' is not in a domain of the module (Modules.Wajub.Admin or Modules.Wajub.Shop)';
                }
            }
        }
    } elseif ($extension === 'js') {
        $code = preg_replace('#/\*.*?\*/|(?<![:"\'])//[^\n]*#s', '', $content) ?? $content;
        foreach ($forbiddenJs as $pattern => $why) {
            if (preg_match($pattern, $code) === 1) {
                $problems[] = $relative . ': ' . $why;
            }
        }
    }
}

if (!is_file($root . '/index.php')) {
    $problems[] = 'index.php: missing at the root';
}
foreach (['logo.png', 'config.xml', 'LICENSE', 'wajub.php'] as $required) {
    if (!is_file($root . '/' . $required)) {
        $problems[] = $required . ': missing';
    }
}

// One version: config.xml says what the module class says.
$module = is_file($root . '/wajub.php') ? (string) file_get_contents($root . '/wajub.php') : '';
$config = is_file($root . '/config.xml') ? (string) file_get_contents($root . '/config.xml') : '';
$classVersion = preg_match("/const VERSION = '([0-9]+\\.[0-9]+\\.[0-9]+)';/", $module, $match) === 1 ? $match[1] : '';
$configVersion = preg_match('/<version><!\[CDATA\[([^\]]*)\]\]><\/version>/', $config, $match) === 1 ? $match[1] : '';
if ($classVersion === '' || $classVersion !== $configVersion) {
    $problems[] = 'config.xml: version ' . $configVersion . ', but Wajub::VERSION is ' . $classVersion;
}
if (preg_match('/->version = self::VERSION;/', $module) !== 1) {
    $problems[] = 'wajub.php: $this->version is not Wajub::VERSION';
}
// And the same words: PrestaShop lists the module from config.xml when it
// is newer than wajub.php, and Addons reads it.
foreach (['displayName', 'description', 'confirmUninstall'] as $field) {
    $inClass = preg_match('/->' . $field . ' = \$this->trans\(\'((?:[^\'\\\\]|\\\\.)*)\'/', $module, $match) === 1 ? stripslashes($match[1]) : '';
    $inConfig = preg_match('/<' . $field . '><!\\[CDATA\\[(.*?)\\]\\]><\\/' . $field . '>/s', $config, $match) === 1 ? $match[1] : '';
    if ($inClass === '' || $inClass !== $inConfig) {
        $problems[] = 'config.xml: ' . $field . ' "' . $inConfig . '", but wajub.php says "' . $inClass . '"';
    }
}
foreach (['name' => "'wajub'", 'tab' => "'payments_gateways'", 'author' => "'Wajub'"] as $field => $literal) {
    $inClass = preg_match('/->' . $field . ' = (\'[^\']*\');/', $module, $match) === 1 ? $match[1] : '';
    $inConfig = preg_match('/<' . $field . '>(?:<!\\[CDATA\\[)?([^<\\]]*)(?:\\]\\]>)?<\\/' . $field . '>/', $config, $match) === 1 ? "'" . $match[1] . "'" : '';
    if ($inClass !== $literal || $inConfig !== $literal) {
        $problems[] = 'config.xml: ' . $field . ' ' . $inConfig . ', but wajub.php says ' . $inClass;
    }
}

// Each e-mail the module sends, in every language it ships, as HTML and text.
foreach (glob($root . '/mails/en/*.html') ?: [] as $template) {
    $name = basename($template, '.html');
    foreach (['en', 'fr'] as $language) {
        foreach (['html', 'txt'] as $format) {
            if (!is_file($root . '/mails/' . $language . '/' . $name . '.' . $format)) {
                $problems[] = 'mails/' . $language . '/' . $name . '.' . $format . ': missing';
            }
        }
    }
}

foreach ($problems as $problem) {
    fwrite(STDOUT, $problem . PHP_EOL);
}
fwrite(STDOUT, $problems === [] ? "Module files: OK\n" : count($problems) . " problem(s)\n");
exit($problems === [] ? 0 : 1);
