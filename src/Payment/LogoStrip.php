<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Payment;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The logos of a payment option as one picture, beside its name at
 * checkout: PrestaShop draws one image per option (PaymentOption::setLogo(),
 * an <img> in the theme's template), and a payer recognises a method by the
 * networks it takes (MTN and Orange, Visa and Mastercard).
 *
 * An SVG file of the brands' own artwork (views/img/methods, all SVG), each
 * drawn Methods::LOGO_HEIGHT high and as wide as its shape, 4px apart, a
 * logo without a background of its own on a white badge, as the plugin draws
 * them. An SVG an <img> shows may load nothing else, so each logo's drawing
 * is written inside it, as a nested <svg>. Written once, in the folder of
 * pictures PrestaShop makes itself (img/tmp, as its thumbnails), under a
 * name its content gives: the same logos are the same file, a browser keeps
 * it, and logos that change make a new one.
 */
final class LogoStrip
{
    private const GAP = 4;

    /** The white badge's padding around a logo that has no background of its own. */
    private const PAD_X = 4;

    private const PAD_Y = 3;

    /** How the files are named, before their content's hash. */
    public const PREFIX = 'wajub-logos-';

    /** @var array<string, string> what this request wrote or found, by its logos */
    private static $files = [];

    /**
     * What the strip shows, in words: the brands' names, in the order shown.
     * PrestaShop's themes draw an option's logo with no text alternative;
     * the checkout's script gives it this one (views/js/checkout-options.js).
     *
     * @param list<string> $keys
     */
    public static function alt(array $keys): string
    {
        $names = [];
        foreach ($keys as $key) {
            if (Methods::logoFile($key) !== '') {
                $names[] = Methods::logoName($key);
            }
        }

        return implode(', ', array_values(array_unique(array_filter($names))));
    }

    /**
     * The strip's file, written in `$into` unless it is there already: its
     * path, or '' for no logo, or a folder that takes no file.
     *
     * @param list<string> $keys the logos, in the order shown
     * @param string $directory where their files are (views/img/methods)
     * @param string $into where the strip is written (PrestaShop's img/tmp)
     */
    public static function file(array $keys, string $directory, string $into): string
    {
        $memo = implode(',', $keys) . '|' . $directory . '|' . $into;
        if (isset(self::$files[$memo])) {
            return self::$files[$memo];
        }
        $svg = self::svg($keys, $directory);
        if ($svg === '') {
            return self::$files[$memo] = '';
        }

        $path = rtrim($into, '/') . '/' . self::PREFIX . substr(hash('sha256', $svg), 0, 20) . '.svg';
        if (!is_file($path)) {
            // Written beside, then moved in place: a page drawn meanwhile
            // never shows half a file.
            $partial = $path . '.' . bin2hex(random_bytes(4)) . '.part';
            if (!is_dir($into) || @file_put_contents($partial, $svg) !== strlen($svg) || !@rename($partial, $path)) {
                if (is_file($partial)) {
                    @unlink($partial);
                }

                return self::$files[$memo] = '';
            }
        }

        return self::$files[$memo] = $path;
    }

    /**
     * The strip's drawing: '' for no logo.
     *
     * @param list<string> $keys the logos, in the order shown
     * @param string $directory where their files are (views/img/methods)
     */
    public static function svg(array $keys, string $directory): string
    {
        $height = Methods::LOGO_HEIGHT;
        $x = 0.0;
        $parts = '';
        $names = [];
        foreach ($keys as $key) {
            $file = Methods::logoFile($key);
            $path = rtrim($directory, '/') . '/' . $file;
            if ($file === '' || !is_file($path)) {
                continue;
            }
            $drawing = self::drawing((string) \Tools::file_get_contents($path));
            if ($drawing === null) {
                continue;
            }
            $width = (float) Methods::logoWidth($key);
            if ($x > 0) {
                $x += self::GAP;
            }

            if (Methods::logoIsPlain($key)) {
                $innerHeight = $height - 2 * self::PAD_Y;
                $innerWidth = round($width * $innerHeight / $height, 2);
                $badge = $innerWidth + 2 * self::PAD_X;
                $parts .= sprintf('<rect x="%s" y="0" width="%s" height="%d" rx="3" fill="#fff"/>', self::n($x), self::n($badge), $height);
                $parts .= self::place($drawing, $x + self::PAD_X, self::PAD_Y, $innerWidth, $innerHeight);
                $x += $badge;
            } else {
                $parts .= self::place($drawing, $x, 0, $width, $height);
                $x += $width;
            }
            $names[] = Methods::logoName($key);
        }

        if ($parts === '') {
            return '';
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$s" height="%2$d" viewBox="0 0 %1$s %2$d"><title>%3$s</title>%4$s</svg>',
            self::n($x),
            $height,
            htmlspecialchars(implode(', ', $names), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $parts
        );
    }

    /**
     * Deletes every strip written in `$into`: uninstalling.
     */
    public static function forget(string $into): void
    {
        foreach (glob(rtrim($into, '/') . '/' . self::PREFIX . '*.svg') ?: [] as $file) {
            @unlink($file);
        }
        self::$files = [];
    }

    /**
     * A logo file's root element's attributes but its size and place, and
     * its drawing; null for a file that is no SVG.
     *
     * @return array{attributes: string, body: string}|null
     */
    private static function drawing(string $file): ?array
    {
        if (preg_match('#<svg\b([^>]*)>(.*)</svg>\s*$#s', $file, $match) !== 1) {
            return null;
        }
        // Placed and sized by the strip.
        $attributes = (string) preg_replace('#\s(?:width|height|x|y)="[^"]*"#', '', ' ' . $match[1]);

        return ['attributes' => trim($attributes), 'body' => $match[2]];
    }

    /**
     * @param array{attributes: string, body: string} $drawing
     */
    private static function place(array $drawing, float $x, float $y, float $width, float $height): string
    {
        return sprintf('<svg %s x="%s" y="%s" width="%s" height="%s">%s</svg>', $drawing['attributes'], self::n($x), self::n($y), self::n($width), self::n($height), $drawing['body']);
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
