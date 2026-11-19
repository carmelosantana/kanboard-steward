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
        return $defaults;
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
}
