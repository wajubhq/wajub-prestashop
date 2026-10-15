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
 * The Mailer on PrestaShop's Mail::send(), with the module's templates
 * (mails/en and mails/fr; PrestaShop falls back to the shop's language,
 * then English, for another).
 */
final class ShopMailer implements Mailer
{
    /** @var string */
    private $templates;

    /**
     * @param string $templates the module's mails/ folder
     */
    public function __construct(string $templates)
    {
        $this->templates = rtrim($templates, '/') . '/';
    }

    public function shopEmail(int $idShop): string
    {
        $email = trim((string) \Configuration::get('PS_SHOP_EMAIL', null, null, $idShop));

        return \Validate::isEmail($email) ? $email : '';
    }

    public function translator(int $idShop): Translator
    {
        return new ShopTranslator($this->language($idShop));
    }

    public function send(int $idShop, string $to, string $subject, string $template, array $vars): bool
    {
        $language = $this->language($idShop);
        $placeholders = [];
        foreach ($vars as $name => $value) {
            $placeholders['{' . $name . '}'] = $value;
            $placeholders['{' . $name . '_html}'] = nl2br(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), false);
        }

        return (bool) \Mail::send(
            $language !== null ? (int) $language->id : (int) \Configuration::get('PS_LANG_DEFAULT'),
            $template,
            $subject,
            $placeholders,
            $to,
            null,
            null,
            null,
            null,
            null,
            $this->templates,
            false,
            $idShop
        );
    }

    private function language(int $idShop): ?\Language
    {
        $language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT', null, null, $idShop));

        return \Validate::isLoadedObject($language) ? $language : null;
    }
}
