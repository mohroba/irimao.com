<?php

namespace IMAOCustom\Helpers;

class CompetitionTypeAssignments
{
    private const OPTION_KEY = 'imao_age_category_type_map';

    /**
     * Retrieve the assignment map between age categories and competition types.
     *
     * @return array<int,array<int>>
     */
    public static function get_map(): array
    {
        if (!\function_exists('get_option')) {
            return [];
        }

        $raw = \get_option(self::OPTION_KEY, []);
        return self::normalize_map($raw);
    }

    /**
     * Persist the assignment map.
     *
     * @param array<int,array<int|string>> $map
     */
    public static function save_map(array $map): void
    {
        if (!\function_exists('update_option')) {
            return;
        }

        \update_option(self::OPTION_KEY, self::normalize_map($map));
    }

    /**
     * Get assigned competition type IDs for the provided age categories.
     *
     * @param array<int,int|string> $age_ids
     * @return array<int,int>
     */
    public static function types_for_ages(array $age_ids): array
    {
        $map = self::get_map();
        if (!$map) {
            return [];
        }

        $found = [];
        foreach ($age_ids as $age_id) {
            $age = (int) $age_id;
            if ($age <= 0 || empty($map[$age])) {
                continue;
            }
            foreach ($map[$age] as $type_id) {
                $found[$type_id] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Normalize assignment map ensuring integer keys and values.
     *
     * @param mixed $raw
     * @return array<int,array<int>>
     */
    private static function normalize_map($raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $age_id => $types) {
            $age = (int) $age_id;
            if ($age <= 0) {
                continue;
            }
            $type_ids = self::normalize_types($types);
            if ($type_ids) {
                $normalized[$age] = $type_ids;
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $types
     * @return array<int>
     */
    private static function normalize_types($types): array
    {
        $clean = [];
        foreach ((array) $types as $type_id) {
            $id = (int) $type_id;
            if ($id > 0) {
                $clean[$id] = true;
            }
        }

        return array_keys($clean);
    }
}
