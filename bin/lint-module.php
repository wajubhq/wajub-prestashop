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
 * - no function the Addons validator refuses, nor debugging left behind,
 *   nor a shutdown function (the module's end of request is Symfony's
 *   kernel.terminate), nor base64 (pictures are files);
 * - no request value read but through Tools::getValue(), by its name
 *   written out and cast to its type as it is read, nor the server's
 *   globals ($_SERVER: Symfony's Request reads them), nor PHP's session
 *   (messages for the next page go through PrestaShop's notifications), no
 *   file read or copied but through Tools, as the validator asks;
 * - no HTML printed from PHP: templates draw the pages, and link no script
 *   or style themselves (PrestaShop's media lists do);
 * - every variable a template prints is escaped (`|escape:'htmlall':'UTF-8'`,
 *   or a number: `|intval`, `|floatval`), and every wording of a template
 *   is in one of the module's domains;
 * - config.xml says what the module class says: its version
 *   (Wajub::VERSION), name, tab, author, display name, description and
 *   uninstall confirmation; and each e-mail template exists in English and
 *   French, as HTML and as text;
 * - the PrestaShop versions the module says it runs on end at '9.99.99',
 *   written out: never _PS_VERSION_, which the validator rejects;
 * - a .htaccess at the root refuses a request for any PHP file of the
 *   module, on Apache 2.2 (Deny from all) and 2.4 (Require all denied);
 * - in a zip (no bin/ beside the module), composer.json without the
 *   developers' part (require-dev, scripts).
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
            // developers' own, and never ship either; but .htaccess, which
            // is checked on its own below.
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
    '/\$_SESSION\b/' => '$_SESSION: messages for the next page go through PrestaShop\'s notifications (Checkout\\KeptNotices)',
    '/\bregister_shutdown_function\s*\(/' => 'register_shutdown_function(): the end of a back office request is Symfony\'s kernel.terminate (Refund\\RefundFormsEnd)',
    '/\bbase64_(en|de)code\s*\(/' => 'base64: ship pictures as files',
    '/Tools::getValue\(\s*(?![\'"])/' => 'Tools::getValue() of a name not written out',
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
        // Each value cast to its type as it is read.
        if (preg_match_all('/(.{0,12})Tools::getValue\(\s*([\'"][^\'"]*[\'"])/', $code, $reads, PREG_SET_ORDER) > 0) {
            foreach ($reads as $read) {
                if (preg_match('/\((int|string|bool|float|array)\)\s*\\\\?$/', $read[1]) !== 1) {
                    $problems[] = $relative . ': Tools::getValue(' . $read[2] . ') is not cast to its type as it is read';
                }
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
        if (preg_match('/<script\b[^>]*\bsrc=|<link\b[^>]*\bstylesheet/i', $code) === 1) {
            $problems[] = $relative . ': a script or a style linked from the template: add it through PrestaShop (addJS(), addCSS(), registerJavascript())';
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

// The versions of PrestaShop the module runs on: up to every 9.x, written
// out, as Addons asks. _PS_VERSION_ would say any version to come.
if (preg_match('/->ps_versions_compliancy = \[([^\]]*)\];/', $module, $match) !== 1) {
    $problems[] = 'wajub.php: no ps_versions_compliancy';
} elseif (str_contains($match[1], '_PS_VERSION_') || preg_match("/'max' => '9\\.99\\.99'/", $match[1]) !== 1) {
    $problems[] = "wajub.php: ps_versions_compliancy's max is not '9.99.99' (never _PS_VERSION_)";
}

// No PHP file of the module answers a request for its own address: the
// shop's index.php runs its pages. Apache 2.4 (mod_authz_core) and 2.2.
$htaccess = is_file($root . '/.htaccess') ? (string) file_get_contents($root . '/.htaccess') : '';
$rules = preg_replace('/^\s*#[^\n]*$/m', '', $htaccess) ?? $htaccess;
$refuses = static function (string $condition, string $refusal) use ($rules): bool {
    return preg_match('/<IfModule\s+' . preg_quote($condition, '/') . '>\s*<Files\s+"?\*\.php"?>[^<]*' . $refusal . '[^<]*<\/Files>\s*<\/IfModule>/i', $rules) === 1;
};
if ($htaccess === '') {
    $problems[] = '.htaccess: missing at the root (it refuses a request for the module\'s PHP files)';
} else {
    if (!$refuses('mod_authz_core.c', 'Require\s+all\s+denied')) {
        $problems[] = '.htaccess: does not refuse *.php on Apache 2.4 (<IfModule mod_authz_core.c>, Require all denied)';
    }
    if (!$refuses('!mod_authz_core.c', 'Order\s+allow,deny\s+Deny\s+from\s+all')) {
        $problems[] = '.htaccess: does not refuse *.php on Apache 2.2 (<IfModule !mod_authz_core.c>, Order allow,deny, Deny from all)';
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

// A zip's composer.json says how the module's classes load, not how its
// developers work (bin/build-zip.sh trims it).
if (!is_dir($root . '/bin') && is_file($root . '/composer.json')) {
    $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
    foreach (['require-dev', 'scripts'] as $part) {
        if (!is_array($composer) || array_key_exists($part, $composer)) {
            $problems[] = 'composer.json: ' . (is_array($composer) ? $part . ' ships' : 'not JSON');
        }
    }
}

foreach ($problems as $problem) {
    fwrite(STDOUT, $problem . PHP_EOL);
}
fwrite(STDOUT, $problems === [] ? "Module files: OK\n" : count($problems) . " problem(s)\n");
exit($problems === [] ? 0 : 1);
