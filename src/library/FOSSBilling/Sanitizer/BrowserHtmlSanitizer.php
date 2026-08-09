<?php

declare(strict_types=1);
/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace FOSSBilling\Sanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class BrowserHtmlSanitizer
{
    /**
     * Symfony's HtmlSanitizer defaults to a 20 000-char input cap and silently
     * truncates anything beyond it. Rich theme settings pages (e.g. meroserver)
     * are far larger, which chopped off every section past the halfway point —
     * the Site Links / footer controls simply never reached the browser. Theme
     * settings HTML is trusted (theme-author template already run through the
     * Twig sandbox), so a generous cap is safe here.
     */
    private const int THEME_SETTINGS_MAX_INPUT_LENGTH = 1_000_000;

    private static ?HtmlSanitizer $adapterSanitizer = null;
    private static ?HtmlSanitizer $themeSettingsSanitizer = null;

    public static function sanitizeAdapterHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        self::$adapterSanitizer ??= new HtmlSanitizer(self::createBaseConfig());

        return trim(self::$adapterSanitizer->sanitize(self::preSanitize($html)));
    }

    public static function sanitizeThemeSettingsHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        self::$themeSettingsSanitizer ??= new HtmlSanitizer(self::createThemeSettingsConfig());

        return trim(self::$themeSettingsSanitizer->sanitize(self::preSanitize($html)));
    }

    private static function createThemeSettingsConfig(): HtmlSanitizerConfig
    {
        return self::createBaseConfig()
            ->withMaxInputLength(self::THEME_SETTINGS_MAX_INPUT_LENGTH)
            ->allowElement('input', [
                'type', 'name', 'value', 'id', 'checked', 'placeholder', 'accept',
                'multiple', 'min', 'max', 'step', 'readonly', 'disabled', 'required',
                'autocomplete', 'size', 'maxlength', 'minlength', 'pattern',
            ])
            ->allowElement('select', [
                'name', 'id', 'multiple', 'disabled', 'required', 'size',
            ])
            ->allowElement('option', [
                'value', 'selected', 'disabled', 'label',
            ])
            ->allowElement('optgroup', [
                'label', 'disabled',
            ])
            ->allowElement('textarea', [
                'name', 'id', 'rows', 'cols', 'placeholder', 'readonly', 'disabled',
                'required', 'maxlength', 'minlength', 'wrap',
            ])
            ->allowAttribute('class', '*')
            ->allowAttribute('id', '*')
            ->allowAttribute('title', '*')
            ->allowAttribute('for', ['label']);
    }

    private static function createBaseConfig(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https']);
    }

    private static function preSanitize(string $html): string
    {
        $html = str_replace("\0", '', $html);
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', (string) $html);
        $html = preg_replace('#<script\b[^>]*/?>#is', '', (string) $html);
        $html = preg_replace('#<style\b[^>]*/?>#is', '', (string) $html);

        return (string) $html;
    }
}
