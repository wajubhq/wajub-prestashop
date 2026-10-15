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
 * An SVG of the brands' own artwork (views/img/methods), each drawn
 * Methods::LOGO_HEIGHT high and as wide as its shape, 4px apart, a logo
 * without a background of its own on a white badge, as the plugin draws
 * them: written inline as a data: address, its pictures inside it (an SVG
 * an <img> shows may load nothing else). A few kilobytes, the same for the
 * same logos: browsers keep it with the page.
 */
final class LogoStrip
{
    private const GAP = 4;

    /** The white badge's padding around a logo that has no background of its own. */
    private const PAD_X = 4;

    private const PAD_Y = 3;

    /** @var array<string, string> what this request built, by its logos */
    private static $built = [];

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
     * @param list<string> $keys the logos, in the order shown
     * @param string $directory where their files are (views/img/methods)
     */
    public static function dataUri(array $keys, string $directory): string
    {
        $memo = implode(',', $keys) . '|' . $directory;
        if (isset(self::$built[$memo])) {
            return self::$built[$memo];
        }

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
            $bytes = (string) \Tools::file_get_contents($path);
            if ($bytes === '') {
                continue;
            }
            $type = str_ends_with($file, '.png') ? 'image/png' : 'image/svg+xml';
            $href = 'data:' . $type . ';base64,' . base64_encode($bytes);
            $width = (float) Methods::logoWidth($key);
            if ($x > 0) {
                $x += self::GAP;
            }

            if (Methods::logoIsPlain($key)) {
                $innerHeight = $height - 2 * self::PAD_Y;
                $innerWidth = round($width * $innerHeight / $height, 2);
                $badge = $innerWidth + 2 * self::PAD_X;
                $parts .= sprintf('<rect x="%s" y="0" width="%s" height="%d" rx="3" fill="#fff"/>', self::n($x), self::n($badge), $height);
                $parts .= sprintf('<image x="%s" y="%d" width="%s" height="%d" href="%s"/>', self::n($x + self::PAD_X), self::PAD_Y, self::n($innerWidth), $innerHeight, $href);
                $x += $badge;
            } else {
                $parts .= sprintf('<image x="%s" y="0" width="%s" height="%d" href="%s"/>', self::n($x), self::n($width), $height, $href);
                $x += $width;
            }
            $names[] = Methods::logoName($key);
        }

        if ($parts === '') {
            return self::$built[$memo] = '';
        }

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$s" height="%2$d" viewBox="0 0 %1$s %2$d"><title>%3$s</title>%4$s</svg>',
            self::n($x),
            $height,
            htmlspecialchars(implode(', ', $names), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
            $parts
        );

        return self::$built[$memo] = 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
