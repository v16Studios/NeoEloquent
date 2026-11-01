<?php

namespace Vinelab\NeoEloquent;

class Helpers
{
    /**
     * Determine whether an array is associative.
     *
     * @param array $array
     *
     * @return bool
     */
    public static function isAssocArray($array)
    {
        return is_array($array) && !array_is_list($array);
    }
}
