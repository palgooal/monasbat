<?php
if (!defined('ABSPATH')) exit;

/** Single fail-closed authority for destructive Salla not-member removal. */
final class PGE_Salla_Not_Member_Removal_Feature
{
    const OPTION_NAME = 'pge_salla_not_member_removal_enabled';

    public static function enabled()
    {
        return in_array(get_option(self::OPTION_NAME, null), [1, '1', true], true);
    }
}
