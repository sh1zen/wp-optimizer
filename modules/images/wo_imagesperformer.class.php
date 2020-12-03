<?php

class WO_ImagesPerformer
{
    /**
     * @var
     */
    private static $_instance;

    /**
     * @return WO_ImagesPerformer
     */
    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }


}