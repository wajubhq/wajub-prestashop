<?php

/**
 * Wajub for PrestaShop
 *
 * @author    Wajub
 * @copyright Since 2026 Wajub
 * @license   https://opensource.org/licenses/MIT MIT License
 */
declare(strict_types=1);

namespace Wajub\PrestaShop\Support;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The e-mails the module sends to the merchant (a key about to expire):
 * PrestaShop's own mail settings and templates, in the shop's language.
 */
interface Mailer
{
    /** The address the shop's e-mails come from and the merchant reads: its "Shop e-mail". '' when none is set. */
    public function shopEmail(int $idShop): string;

    /** A translator in the shop's default language, for what an e-mail of that shop says. */
    public function translator(int $idShop): Translator;

    /**
     * Sends the module's template `template` (mails/<language>/<template>.html
     * and .txt) to `to`, in the shop's default language. `vars` are written
     * into the text e-mail as they are; the HTML one gets each escaped, under
     * the same name with `_html` added.
     *
     * @param array<string, string> $vars by placeholder, without braces
     */
    public function send(int $idShop, string $to, string $subject, string $template, array $vars): bool;
}
