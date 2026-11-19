<?php
namespace Kanboard\Plugin\Steward\Model;

/**
 * Single source of truth for every steward_* config key: its default and how a submitted value is cleaned.
 * Settings save passes the request through filter() only, never straight to configModel->save().
 */
final class SettingsSchema
{
    public const FEATURES = ['markdown', 'switching', 'branding', 'project_pages', 'public_pages', 'explore', 'test_email', 'banner'];

    /** Features on for a fresh install; everything else starts off. */
    private const ON_BY_DEFAULT = ['markdown'];

    /** key => [default, min, max] */
    private const NUMBERS = [
        'steward_switch_idle_minutes' => [60, 5, 480],
        'steward_audit_retention_days' => [180, 7, 3650],
    ];

    /**
     * Branding keys (spec §3), all empty by default. Only Branding and the branding page write them; filter()
     * never passes them, so the settings form can't point a path at another object (images are served publicly).
     */
    public const BRANDING = [
        'steward_site_name',
        'steward_logo_path',
        'steward_logo_hash',
        'steward_favicon32_path',
        'steward_favicon180_path',
        'steward_favicon_hash',
    ];

    /**
     * Announcement banner keys (spec §7) => default. Only the banner page writes them, through filterBanner();
     * filter() never passes them. Start and end are Unix timestamps, '' for open-ended.
     */
    public const BANNER = [
        'steward_banner_message' => '',
        'steward_banner_level' => 'info',
        'steward_banner_start' => '',
        'steward_banner_end' => '',
        'steward_banner_dismissible' => '1',
        'steward_banner_login' => '0',
        'steward_banner_public' => '0',
    ];

    public const BANNER_LEVELS = ['info', 'warning'];

    /** Characters (mb_strlen) in the raw message. */
    public const BANNER_MESSAGE_MAX = 500;

    private const BANNER_SWITCHES = ['steward_banner_dismissible', 'steward_banner_login', 'steward_banner_public'];

    public static function featureKey(string $feature): string
    {
        return 'steward_'.$feature;
    }

    /** @return array<string,string> */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::FEATURES as $feature) {
            $defaults[self::featureKey($feature)] = in_array($feature, self::ON_BY_DEFAULT, true) ? '1' : '0';
        }
        foreach (self::NUMBERS as $key => [$default]) {
            $defaults[$key] = (string) $default;
        }
        foreach (self::BRANDING as $key) {
            $defaults[$key] = '';
        }
        return $defaults + self::BANNER;
    }

    /**
     * Keeps only known keys; switches become '1'/'0', numbers are clamped. A non-numeric number is dropped
     * so the stored value stays.
     *
     * @return array<string,string>
     */
    public static function filter(array $values): array
    {
        $clean = [];
        foreach (self::FEATURES as $feature) {
            $key = self::featureKey($feature);
            if (array_key_exists($key, $values)) {
                $clean[$key] = ! empty($values[$key]) ? '1' : '0';
            }
        }
        foreach (self::NUMBERS as $key => [, $min, $max]) {
            if (isset($values[$key]) && is_scalar($values[$key]) && is_numeric($values[$key])) {
                $clean[$key] = (string) max($min, min($max, (int) $values[$key]));
            }
        }
        return $clean;
    }

    /**
     * The banner form's values, all BANNER keys or an error (already translated) and nothing else. Times come in
     * the admin's format and timezone; $toTimestamp turns one into a Unix timestamp, 0 when it can't.
     *
     * @param callable(string):int $toTimestamp
     * @return array{values:array<string,string>,error:?string}
     */
    public static function filterBanner(array $values, callable $toTimestamp): array
    {
        $message = is_string($values['steward_banner_message'] ?? null) ? $values['steward_banner_message'] : '';
        if (! mb_check_encoding($message, 'UTF-8')) {
            return ['values' => [], 'error' => t('The message is not valid UTF-8 text')];
        }
        if (mb_strlen($message, 'UTF-8') > self::BANNER_MESSAGE_MAX) {
            return ['values' => [], 'error' => t('The message is too long (max %d characters)', self::BANNER_MESSAGE_MAX)];
        }
        $level = $values['steward_banner_level'] ?? null;
        $clean = [
            'steward_banner_message' => trim($message),
            'steward_banner_level' => in_array($level, self::BANNER_LEVELS, true) ? $level : 'info',
        ];
        foreach (['steward_banner_start', 'steward_banner_end'] as $key) {
            $raw = is_string($values[$key] ?? null) ? trim($values[$key]) : '';
            $time = $raw === '' ? 0 : (int) $toTimestamp($raw);
            if ($raw !== '' && $time <= 0) {
                return ['values' => [], 'error' => t('Enter the start and end times in the format shown, or leave them empty')];
            }
            $clean[$key] = $time === 0 ? '' : (string) $time;
        }
        if ($clean['steward_banner_start'] !== '' && $clean['steward_banner_end'] !== ''
            && (int) $clean['steward_banner_end'] <= (int) $clean['steward_banner_start']) {
            return ['values' => [], 'error' => t('The end time must be after the start time')];
        }
        foreach (self::BANNER_SWITCHES as $key) {
            $clean[$key] = ! empty($values[$key]) ? '1' : '0';
        }
        return ['values' => $clean, 'error' => null];
    }
}
