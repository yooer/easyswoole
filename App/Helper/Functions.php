<?php
if (!function_exists('cache')) {
    /**
     * @return \App\Helper\FastCache
     */
    function cache()
    {
        return \App\Helper\FastCache::getInstance();
    }
}

if (!function_exists('mongo')) {
    /**
     * @return \App\Helper\MongoDbHelper
     */
    function mongo()
    {
        return \App\Helper\MongoDbHelper::getInstance();
    }
}
